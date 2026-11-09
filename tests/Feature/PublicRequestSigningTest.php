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

    public function test_public_page_offers_draft_download_and_scan_upload_when_signing(): void
    {
        $this->request('signing');

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee(route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'draft']), false)
            ->assertSee('Unduh draft dokumen')->assertSee('Langkah selanjutnya')
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
            ->assertSee('Scan sudah diterima. Pengaju perlu menyerahkan dokumen asli bertanda tangan ke tim Legal.')
            ->assertSee('Bila terlambat, alasannya bisa ditulis lewat tautan status yang dikirim ke WhatsApp pengaju.')
            ->assertDontSee('mengunggah scan');
    }

    public function test_lookup_page_tells_what_to_do_while_waiting_for_signed_scan(): void
    {
        $submission = $this->request('signing');

        $this->post(route('requests.public.lookup.submit'), ['submission_id' => (string) $submission->id, 'requester_phone' => '081234567890'])->assertOk()
            ->assertSee('Menunggu scan bertanda tangan')
            ->assertSee('Draft sudah siap. Pengaju perlu mengunduh draft, menandatanganinya, lalu mengunggah scan-nya lewat tautan status yang dikirim ke WhatsApp pengaju.')
            ->assertSee('Halaman ini hanya untuk melihat status.')
            ->assertDontSee('<form', false);
    }

    public function test_scan_upload_and_reason_confirm_what_happens_next(): void
    {
        $submission = $this->request('signing');
        $status = route('requests.public.status', ['token' => self::TOKEN]);

        $this->followingRedirects()->post(route('requests.public.scan', ['token' => self::TOKEN]), ['scan' => $this->pdf()])
            ->assertSee('Scan diterima. Serahkan dokumen asli ke tim legal paling lambat 27 Okt 2026.')
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
            ->assertSee('Ganti scan (bila salah unggah)')
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
