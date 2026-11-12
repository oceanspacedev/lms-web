<?php

namespace Tests\Feature;

use App\Models\DocumentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Halaman status publik pada tahap tanda tangan dan penyerahan dokumen fisik. */
class PublicRequestSigningTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6abcd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(8, 0));
        Storage::fake('local');
        Storage::fake('s3');
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response([], 202)]);
    }

    /** @param array<string, mixed> $attributes */
    private function request(string $status, array $attributes = []): DocumentRequest
    {
        Storage::disk('local')->put('lms/requests/perjanjian/draft.pdf', 'draft');

        return DocumentRequest::factory()->create([
            'status' => $status, 'public_token' => self::TOKEN, 'requester_name' => 'Pengaju Publik', 'requester_phone' => '081234567890',
            'draft_path' => 'lms/requests/perjanjian/draft.pdf', 'draft_name' => 'draft.pdf', 'draft_issued_at' => now()->subDay(),
            'history' => [['status' => $status, 'user' => 'Staf', 'user_id' => 1, 'at' => now()->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf');
    }

    public function test_finished_request_lets_the_applicant_download_legal_scan_draft_and_soft_file(): void
    {
        Storage::disk('local')->put('lms/requests/perjanjian/signed.pdf', 'ttd');
        Storage::disk('local')->put('lms/requests/perjanjian/scan.pdf', 'scan legal');
        $this->request('archived', [
            'signed_path' => 'lms/requests/perjanjian/signed.pdf', 'signed_name' => 'kontrak-ttd.pdf', 'signed_uploaded_at' => now()->subDay(), 'physical_received_at' => now(),
            'scan_path' => 'lms/requests/perjanjian/scan.pdf', 'scan_name' => 'kontrak-asli-cap.pdf',
        ]);
        $scan = route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'scan']);
        $draft = route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'draft']);
        $signed = route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'signed']);

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Dokumen pengajuan')
            ->assertSeeInOrder([$scan, $draft, $signed], false)
            ->assertSee('Unduh scan dokumen asli (bertanda tangan &amp; cap)', false)->assertSee('kontrak-asli-cap.pdf')
            ->assertSee('Unduh draft dokumen')
            ->assertSee('Unduh soft file bertanda tangan yang Anda unggah')->assertSee('kontrak-ttd.pdf')
            ->assertDontSee('<form method="post" enctype="multipart/form-data"', false);

        $this->get($scan)->assertOk()->assertDownload('kontrak-asli-cap.pdf');
        $this->get($draft)->assertOk()->assertDownload('draft.pdf');
        $this->get($signed)->assertOk()->assertDownload('kontrak-ttd.pdf');
    }

    public function test_request_finished_before_legal_scans_still_offers_its_soft_file(): void
    {
        Storage::disk('local')->put('lms/requests/perjanjian/signed.pdf', 'ttd');
        $this->request('archived', ['signed_path' => 'lms/requests/perjanjian/signed.pdf', 'signed_name' => 'kontrak-ttd.pdf', 'physical_received_at' => now()]);

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Unduh soft file bertanda tangan yang Anda unggah')->assertDontSee('Unduh scan dokumen asli');
        $this->get(route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'scan']))->assertNotFound();
    }

    public function test_scanning_page_says_the_scan_follows_and_offers_existing_files(): void
    {
        $this->request('scanning', ['physical_received_at' => now()]);

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Dokumen asli sedang discan')->assertSee('di halaman ini')
            ->assertSee('Unduh draft dokumen')->assertDontSee('Unduh scan dokumen asli')
            ->assertDontSee('<form method="post" enctype="multipart/form-data"', false);
    }

    public function test_progress_follows_the_applicant_view_in_five_steps(): void
    {
        $cases = [
            'submitted' => ['Memeriksa' => 'current', 'Proses Drafting' => 'pending'],
            'drafting' => ['Memeriksa' => 'done', 'Proses Drafting' => 'current', 'Diserahkan' => 'pending'],
            'signing' => ['Proses Drafting' => 'done', 'Diserahkan' => 'current', 'Scanning' => 'pending'],
            'handover' => ['Diserahkan' => 'current', 'Scanning' => 'pending'],
            'scanning' => ['Diserahkan' => 'done', 'Scanning' => 'current', 'Selesai' => 'pending'],
            'archived' => ['Scanning' => 'done', 'Selesai' => 'done'],
        ];
        foreach ($cases as $status => $expected) {
            $steps = collect(DocumentRequest::factory()->make(['status' => $status])->progressSteps())->pluck('state', 'label');
            $this->assertSame(['Memeriksa', 'Proses Drafting', 'Diserahkan', 'Scanning', 'Selesai'], $steps->keys()->all());
            foreach ($expected as $label => $state) {
                $this->assertSame($state, $steps[$label], "{$status}: {$label}");
            }
        }
    }

    public function test_lookup_page_never_offers_file_downloads(): void
    {
        Storage::disk('local')->put('lms/requests/perjanjian/signed.pdf', 'ttd');
        $submission = $this->request('archived', ['signed_path' => 'lms/requests/perjanjian/signed.pdf', 'signed_name' => 'kontrak-ttd.pdf', 'physical_received_at' => now()]);

        $this->post(route('requests.public.lookup.submit'), ['submission_id' => (string) $submission->id, 'requester_phone' => '081234567890'])->assertOk()
            ->assertDontSee('Dokumen pengajuan')->assertDontSee(self::TOKEN);
    }

    public function test_public_page_offers_draft_download_and_scan_upload_when_signing(): void
    {
        $this->request('signing');

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee(route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'draft']), false)
            ->assertSee('Unduh draft dokumen')->assertSee('Langkah selanjutnya')->assertSee('unggah soft file-nya di bawah ini paling lambat <strong>02 Nov 2026</strong>', false)
            ->assertSee(route('requests.public.scan', ['token' => self::TOKEN]), false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertDontSee('Terlambat');
    }

    public function test_public_page_shows_deadline_and_remaining_days_when_handover_is_on_time(): void
    {
        $this->request('handover', ['signed_uploaded_at' => now()->subDays(2), 'signed_path' => 'lms/requests/perjanjian/scan.pdf', 'signed_name' => 'scan.pdf']);

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Batas penyerahan')->assertSee('25 Okt 2026')->assertSee('sisa 5 hari')
            ->assertSee(route('requests.public.scan', ['token' => self::TOKEN]), false)
            ->assertDontSee(route('requests.public.reason', ['token' => self::TOKEN]), false);
    }

    public function test_public_scan_upload_starts_handover(): void
    {
        $submission = $this->request('signing');

        $this->post(route('requests.public.scan', ['token' => self::TOKEN]), ['scan' => $this->pdf()])
            ->assertRedirect(route('requests.public.status', ['token' => self::TOKEN]));

        $submission->refresh();
        $this->assertSame('handover', $submission->status);
        $this->assertNotNull($submission->signed_path);
        Storage::disk('local')->assertExists($submission->signed_path);
        $this->assertNotNull($submission->signed_uploaded_at);
    }

    public function test_public_scan_upload_validates_the_file(): void
    {
        $submission = $this->request('signing');
        $url = route('requests.public.status', ['token' => self::TOKEN]);

        $this->from($url)->post(route('requests.public.scan', ['token' => self::TOKEN]), [])->assertRedirect($url)->assertSessionHasErrors();
        $this->from($url)->post(route('requests.public.scan', ['token' => self::TOKEN]), ['scan' => UploadedFile::fake()->create('virus.exe', 10)])->assertRedirect($url)->assertSessionHasErrors();
        $this->assertSame('signing', $submission->fresh()->status);
    }

    public function test_public_late_reason_is_saved_and_listed(): void
    {
        $submission = $this->request('handover', ['signed_uploaded_at' => now()->subDays(10), 'signed_path' => 'lms/requests/perjanjian/scan.pdf', 'signed_name' => 'scan.pdf']);

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Terlambat')->assertSee(route('requests.public.reason', ['token' => self::TOKEN]), false);

        $this->post(route('requests.public.reason', ['token' => self::TOKEN]), ['reason' => 'Kurir belum sampai ke kantor'])
            ->assertRedirect(route('requests.public.status', ['token' => self::TOKEN]));

        $this->assertSame(1, $submission->lateReasons()->count());
        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertSee('Kurir belum sampai ke kantor');
    }

    public function test_public_late_reason_requires_text_and_lateness(): void
    {
        $submission = $this->request('handover', ['signed_uploaded_at' => now()->subDays(10)]);
        $url = route('requests.public.status', ['token' => self::TOKEN]);

        $this->from($url)->post(route('requests.public.reason', ['token' => self::TOKEN]), ['reason' => ''])->assertRedirect($url)->assertSessionHasErrors('reason');
        $this->assertSame(0, $submission->lateReasons()->count());

        $submission->forceFill(['signed_uploaded_at' => now()->subDay()])->save();
        $this->from($url)->post(route('requests.public.reason', ['token' => self::TOKEN]), ['reason' => 'Terlalu cepat'])->assertRedirect($url)->assertSessionHasErrors('reason');
        $this->assertSame(0, $submission->lateReasons()->count());
    }

    public function test_public_scan_upload_is_refused_after_completion(): void
    {
        foreach (['archived', 'cancelled'] as $status) {
            $submission = $this->request($status, ['public_token' => self::TOKEN]);

            $this->post(route('requests.public.scan', ['token' => self::TOKEN]), ['scan' => $this->pdf()])
                ->assertRedirect(route('requests.public.status', ['token' => self::TOKEN]))
                ->assertSessionHasErrors(['status' => 'Pengajuan ini tidak sedang menunggu dokumen tersebut.']);

            $submission->refresh();
            $this->assertSame($status, $submission->status);
            $this->assertNull($submission->signed_path);
            $this->assertSame(['lms/requests/perjanjian/draft.pdf'], Storage::disk('local')->allFiles('lms/requests/perjanjian'));
            $submission->forceDelete();
        }
    }

    public function test_public_late_reason_is_refused_when_not_in_handover(): void
    {
        $this->request('archived');

        $this->post(route('requests.public.reason', ['token' => self::TOKEN]), ['reason' => 'Alasan'])
            ->assertRedirect(route('requests.public.status', ['token' => self::TOKEN]))
            ->assertSessionHasErrors(['status' => 'Pengajuan ini tidak sedang menunggu dokumen tersebut.']);
    }

    public function test_lookup_page_shows_no_upload_or_reason_forms(): void
    {
        $submission = $this->request('handover', ['signed_uploaded_at' => now()->subDays(10)]);

        $this->post(route('requests.public.lookup.submit'), ['submission_id' => (string) $submission->id, 'requester_phone' => '081234567890'])->assertOk()
            ->assertSee('Status pengajuan')
            ->assertDontSee('<form', false)
            ->assertDontSee(route('requests.public.scan', ['token' => self::TOKEN]), false)
            ->assertDontSee(route('requests.public.reason', ['token' => self::TOKEN]), false)
            ->assertDontSee(self::TOKEN)
            ->assertSee('Menunggu dokumen asli')
            ->assertSee('Soft file sudah diterima. Pengaju perlu menyerahkan dokumen asli bertanda tangan ke tim Legal.')
            ->assertSee('Bila terlambat, alasannya bisa ditulis lewat tautan status yang dikirim ke WhatsApp pengaju.')
            ->assertDontSee('mengunggah soft file');
    }

    public function test_lookup_page_tells_what_to_do_while_waiting_for_signed_scan(): void
    {
        $submission = $this->request('signing');

        $this->post(route('requests.public.lookup.submit'), ['submission_id' => (string) $submission->id, 'requester_phone' => '081234567890'])->assertOk()
            ->assertSee('Menunggu soft file bertanda tangan')
            ->assertSee('Draft sudah diserahkan. Pengaju perlu mengunduh draft, menandatanganinya, lalu mengunggah soft file-nya lewat tautan status yang dikirim ke WhatsApp pengaju.')
            ->assertSee('Halaman ini hanya untuk melihat status.')
            ->assertDontSee('<form', false);
    }

    public function test_scan_upload_and_reason_confirm_what_happens_next(): void
    {
        $submission = $this->request('signing');
        $status = route('requests.public.status', ['token' => self::TOKEN]);

        $this->followingRedirects()->post(route('requests.public.scan', ['token' => self::TOKEN]), ['scan' => $this->pdf()])
            ->assertSee('Soft file diterima. Serahkan dokumen asli ke tim legal paling lambat 27 Okt 2026.')
            ->assertDontSee('Berhasil disimpan.');

        $submission->forceFill(['signed_uploaded_at' => now()->subDays(10)])->save();
        $this->followingRedirects()->post(route('requests.public.reason', ['token' => self::TOKEN]), ['reason' => 'Direktur dinas luar kota'])
            ->assertSee('Alasan keterlambatan terkirim ke tim legal.');
        $this->get($status)->assertDontSee('Alasan keterlambatan terkirim ke tim legal.');
    }

    public function test_late_page_puts_the_reason_form_before_the_scan_replacement(): void
    {
        $this->request('handover', ['signed_uploaded_at' => now()->subDays(10), 'signed_path' => 'lms/requests/perjanjian/scan.pdf', 'signed_name' => 'scan.pdf']);

        $html = $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertDontSee('sebelum batas ini')
            ->assertSee('Ganti soft file (bila salah unggah)')
            ->getContent();

        $this->assertLessThan(strpos($html, 'id="signed-scan"'), strpos($html, 'id="late-reason"'));
    }

    public function test_handover_on_the_deadline_day_says_today(): void
    {
        $this->request('handover', ['signed_uploaded_at' => now()->subDays(7), 'signed_path' => 'lms/requests/perjanjian/scan.pdf']);

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('(hari ini)')->assertDontSee('sisa 0 hari')->assertDontSee('id="late-reason"', false);
    }

    public function test_cancelled_shows_reason_and_stopped_progress(): void
    {
        $this->request('cancelled', ['history' => [
            ['status' => 'submitted', 'user' => 'P', 'user_id' => 1, 'at' => now()->subDays(3)->toIso8601String(), 'note' => null],
            ['status' => 'signing', 'user' => 'S', 'user_id' => 1, 'at' => now()->subDays(2)->toIso8601String(), 'note' => null],
            ['status' => 'cancelled', 'user' => 'S', 'user_id' => 1, 'at' => now()->toIso8601String(), 'note' => 'Kerja sama dibatalkan oleh pihak lawan'],
        ]]);

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Alasan pembatalan')->assertSee('Kerja sama dibatalkan oleh pihak lawan')->assertSee('dihentikan')
            ->assertDontSee(route('requests.public.scan', ['token' => self::TOKEN]), false);
    }

    public function test_public_draft_downloads_straight_away_instead_of_opening_a_preview(): void
    {
        $this->request('signing');
        Storage::disk('local')->put('lms/requests/perjanjian/draft.pdf', "%PDF-1.4\n% draft\n%%EOF\n");

        $response = $this->get(route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'draft']))->assertOk();

        $this->assertSame('attachment; filename=draft.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_public_file_route_rejects_unknown_token_and_kind(): void
    {
        $this->request('signing');

        $this->get(route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'draft']))->assertOk();
        $this->get(route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'other']))->assertNotFound();
        $this->get(route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'signed']))->assertNotFound();
        $this->get(route('requests.public.file', ['token' => str_repeat('z', 64), 'kind' => 'draft']))->assertNotFound();
        $this->get('/pengajuan/pendek/berkas/draft')->assertNotFound();
    }
}
