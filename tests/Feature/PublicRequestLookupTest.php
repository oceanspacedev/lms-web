<?php

namespace Tests\Feature;

use App\Models\DocumentRequest;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pengaju yang kehilangan tautan: tombol salin di halaman status dan cek status dengan nomor pengajuan + nomor WhatsApp.
 */
class PublicRequestLookupTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6abcd';

    private const PHONE = '6281355550000';

    private const GENERIC_MESSAGE = 'Data tidak ditemukan. Periksa nomor pengajuan dan nomor WhatsApp.';

    /** @param array<string, mixed> $attributes */
    private function publicRequest(array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->create([
            'requester_id' => null, 'public_token' => self::TOKEN, 'requester_name' => 'Pengaju Publik', 'requester_phone' => self::PHONE,
            'status' => 'submitted', 'title' => 'Kerja Sama Distribusi',
            'history' => [['status' => 'submitted', 'user' => 'Pengaju Publik', 'user_id' => null, 'at' => '2026-10-01T01:00:00Z', 'note' => null]],
            ...$attributes,
        ]);
    }

    private function lookup(mixed $submissionId, mixed $phone): TestResponse
    {
        return $this->post(route('requests.public.lookup.submit'), ['submission_id' => $submissionId, 'requester_phone' => $phone]);
    }

    public function test_status_page_shows_the_full_link_in_a_readonly_field_with_a_copy_button(): void
    {
        $submission = $this->publicRequest();
        $link = route('requests.public.status', ['token' => self::TOKEN]);

        $html = $this->withSession(['submitted' => true])->get($link)->assertOk()
            ->assertSee('Pengajuan berhasil')
            ->assertSee('Simpan tautan ini untuk melihat perkembangan pengajuan.')
            ->assertSee('Tautan status pengajuan')
            ->assertSee('Salin tautan')
            ->assertSee('Tautan ini juga dikirim ke WhatsApp pengaju.')
            ->assertSee('bila pengajuan perlu direvisi, dapat mengubah lalu mengirim ulang pengajuan')
            ->getContent();

        $this->assertMatchesRegularExpression('/<input id="status-link" type="text" readonly value="'.preg_quote($link, '/').'"/', $html);
        $this->assertStringContainsString('data-copy-button', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('Pengajuan #'.$submission->id, $html);
    }

    public function test_status_page_without_the_submitted_flash_also_shows_the_copy_button(): void
    {
        $this->publicRequest();

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Status pengajuan')->assertDontSee('Pengajuan berhasil')->assertSee('Salin tautan');
    }

    public function test_copy_script_gives_feedback_and_falls_back_to_selecting_the_text(): void
    {
        $script = file_get_contents(public_path('js/request-form.js'));

        $this->assertStringContainsString('navigator.clipboard', $script);
        $this->assertStringContainsString('Tautan disalin', $script);
        $this->assertStringContainsString('input.select()', $script);
        $this->assertStringContainsString("execCommand('copy')", $script);
    }

    public function test_public_form_links_to_the_status_lookup_but_the_revision_page_does_not(): void
    {
        $this->get(route('requests.public.create'))->assertOk()
            ->assertSee('Sudah mengajukan? Cek status pengajuan')->assertSee(route('requests.public.lookup'));

        $this->publicRequest(['status' => 'revision']);
        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Kirim revisi')->assertDontSee('Sudah mengajukan? Cek status pengajuan');
    }

    public function test_lookup_form_asks_for_the_submission_number_and_whatsapp_number_with_private_headers(): void
    {
        $response = $this->get(route('requests.public.lookup'))->assertOk()
            ->assertSee('Nomor pengajuan')->assertSee('Nomor WhatsApp pengaju')
            ->assertSee('action="'.route('requests.public.lookup.submit').'"', false)
            ->assertDontSee(self::GENERIC_MESSAGE);

        $this->assertPrivateHeaders($response);
    }

    public function test_lookup_routes_do_not_clash_with_the_token_routes(): void
    {
        $this->assertSame('/pengajuan/cek', route('requests.public.lookup', absolute: false));
        $this->assertSame('/pengajuan/cek', route('requests.public.lookup.submit', absolute: false));
        $this->assertSame('/pengajuan/'.self::TOKEN, route('requests.public.status', ['token' => self::TOKEN], false));
    }

    public function test_matching_number_and_phone_shows_the_status_read_only_without_the_token(): void
    {
        $submission = $this->publicRequest();

        $response = $this->lookup($submission->id, '081355550000')->assertOk()
            ->assertSee('Status pengajuan')->assertSee('Pengajuan #'.$submission->id)->assertDontSee('Kerja Sama Distribusi')
            ->assertSee('Pengaju Publik')->assertSee('Diperiksa');
        $html = $response->getContent();

        $this->assertStringNotContainsString(self::TOKEN, $html);
        $this->assertStringNotContainsString('/pengajuan/'.substr(self::TOKEN, 0, 10), $html);
        $this->assertStringNotContainsString('Salin tautan', $html);
        $this->assertStringNotContainsString('data-copy-link', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('Kirim revisi', $html);
        $this->assertStringNotContainsString('81355550000', $html);
        $this->assertStringNotContainsString('Pengajuan berhasil', $html);
        $this->assertStringContainsString('Halaman ini hanya untuk melihat status pengajuan.', $html);
        $this->assertPrivateHeaders($response);
    }

    public function test_phone_and_number_formats_are_normalised_before_matching(): void
    {
        $submission = $this->publicRequest();

        foreach ([['#'.$submission->id, '+62 813-5555-0000'], [' '.$submission->id.' ', '6281355550000'], [(string) $submission->id, '0813 5555 0000']] as [$number, $phone]) {
            $this->lookup($number, $phone)->assertOk()->assertSee('Pengajuan #'.$submission->id);
        }
    }

    public function test_lookup_of_a_request_needing_revision_points_to_the_whatsapp_link(): void
    {
        $submission = $this->publicRequest(['status' => 'revision', 'history' => [
            ['status' => 'submitted', 'user' => 'Pengaju Publik', 'user_id' => null, 'at' => '2026-10-01T01:00:00Z', 'note' => null],
            ['status' => 'revision', 'user' => 'Staf', 'user_id' => 1, 'at' => '2026-10-02T01:00:00Z', 'note' => 'Lengkapi lampiran NIB'],
        ]]);

        $html = $this->lookup($submission->id, self::PHONE)->assertOk()
            ->assertSee('Pengajuan perlu direvisi')
            ->assertSee('Buka tautan revisi yang dikirim ke WhatsApp pengaju')
            ->assertSee('Perlu Revisi')
            ->getContent();

        $this->assertStringNotContainsString('Kirim revisi', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);
        $this->assertStringNotContainsString('Salin tautan', $html);
    }

    public function test_lookup_output_is_escaped(): void
    {
        $submission = $this->publicRequest(['requester_name' => '<b>Budi</b>']);

        $this->lookup($submission->id, self::PHONE)->assertOk()
            ->assertDontSee('<b>Budi</b>', false)->assertSee('&lt;b&gt;Budi&lt;/b&gt;', false);
    }

    public function test_lookup_hides_the_title_and_reviewer_notes_that_the_token_link_shows(): void
    {
        $submission = $this->publicRequest(['status' => 'rejected', 'title' => 'Perjanjian Rahasia PT X', 'history' => [
            ['status' => 'submitted', 'user' => 'Pengaju Publik', 'user_id' => null, 'at' => '2026-10-01T01:00:00Z', 'note' => null],
            ['status' => 'revision', 'user' => 'Staf', 'user_id' => 1, 'at' => '2026-10-02T01:00:00Z', 'note' => 'Catatan revisi internal'],
            ['status' => 'rejected', 'user' => 'Staf', 'user_id' => 1, 'at' => '2026-10-03T01:00:00Z', 'note' => 'Alasan tolak internal'],
        ]]);

        $this->lookup($submission->id, self::PHONE)->assertOk()
            ->assertSee('Pengajuan #'.$submission->id)->assertSee('Ditolak')
            ->assertSee('Judul pengajuan dan catatan pemeriksa hanya ditampilkan melalui tautan status')
            ->assertDontSee('Perjanjian Rahasia PT X')->assertDontSee('Catatan revisi internal')
            ->assertDontSee('Alasan tolak internal')->assertDontSee('Alasan penolakan');

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Perjanjian Rahasia PT X')->assertSee('Alasan penolakan')->assertSee('Alasan tolak internal')
            ->assertDontSee('hanya ditampilkan melalui tautan status');
    }

    public function test_status_dates_use_indonesian_month_names(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(1, 0));
        $submission = $this->publicRequest(['history' => [['status' => 'submitted', 'user' => 'Pengaju Publik', 'user_id' => null, 'at' => '2026-10-13T01:00:00Z', 'note' => null]]]);
        $submission->forceFill(['updated_at' => now()])->saveQuietly();

        $html = $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('13 Okt 2026 08:00')->getContent();
        $this->assertStringNotContainsString('Oct', $html);
    }

    public function test_lookup_accepts_the_number_as_written_in_the_whatsapp_message(): void
    {
        $submission = $this->publicRequest();

        $this->lookup('Pengajuan #'.$submission->id, '+62 813-5555-0000')->assertOk()->assertSee('Pengajuan #'.$submission->id);
    }

    public function test_lookup_inputs_are_long_enough_for_numbers_pasted_from_whatsapp(): void
    {
        $html = $this->get(route('requests.public.lookup'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="lookup-submission-id"[^>]*maxlength="30"/', $html);
        $this->assertMatchesRegularExpression('/id="lookup-requester-phone"[^>]*maxlength="25"/', $html);
        $this->assertGreaterThan(strlen('+62 812-3456-7890'), 25);
        $this->assertGreaterThan(strlen('Pengajuan #123456789'), 30);
    }

    public function test_one_whatsapp_number_cannot_be_tried_against_many_submission_numbers_from_many_ips(): void
    {
        $submission = $this->publicRequest();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$attempt])->lookup(900000 + $attempt, self::PHONE)->assertStatus(302);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->lookup($submission->id, '0813 5555 0000')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.2'])->lookup($submission->id, '081399990000')->assertStatus(302);
    }

    public function test_one_submission_number_cannot_be_tried_against_many_whatsapp_numbers_from_many_ips(): void
    {
        $submission = $this->publicRequest();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$attempt])->lookup('#'.$submission->id, '08139999'.str_pad((string) $attempt, 4, '0', STR_PAD_LEFT))->assertStatus(302);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->lookup($submission->id, self::PHONE)->assertStatus(429);
    }

    public function test_too_many_lookups_show_an_indonesian_page_with_the_form(): void
    {
        $submission = $this->publicRequest();
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->lookup($submission->id, '081399990000');
        }

        $response = $this->lookup($submission->id, '081399990000')->assertStatus(429)
            ->assertSee('Terlalu banyak percobaan cek status.')->assertSee('Cek status')
            ->assertSee('action="'.route('requests.public.lookup.submit').'"', false)
            ->assertDontSee('Too Many Requests');
        $this->assertPrivateHeaders($response);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public static function failedLookups(): array
    {
        return [
            'nomor pengajuan tidak ada' => [999999, self::PHONE],
            'nomor WhatsApp salah' => ['@id', '081399990000'],
            'nomor WhatsApp kosong' => ['@id', ''],
            'format nomor WhatsApp salah' => ['@id', 'abc'],
            'format nomor pengajuan salah' => ['abc', self::PHONE],
            'nomor pengajuan terlalu panjang' => ['99999999999999999999', self::PHONE],
            'nomor pengajuan nol' => ['0', self::PHONE],
            'nomor pengajuan negatif' => ['-1', self::PHONE],
            'dua duanya kosong' => ['', ''],
            'nomor pengajuan berupa array' => [['1'], self::PHONE],
            'nomor WhatsApp berupa array' => ['@id', [self::PHONE]],
        ];
    }

    /** Penanda '@id' berarti nomor pengajuan yang benar; data provider berjalan sebelum data dibuat. */
    private function resolveId(mixed $submissionId, DocumentRequest $submission): mixed
    {
        return $submissionId === '@id' ? $submission->id : $submissionId;
    }

    #[DataProvider('failedLookups')]
    public function test_every_failed_lookup_gets_the_same_generic_message_and_status(mixed $submissionId, mixed $phone): void
    {
        $submission = $this->publicRequest();
        $this->publicRequest(['public_token' => null, 'requester_phone' => self::PHONE, 'requester_name' => 'Staf', 'requester_id' => null]);

        $response = $this->lookup($this->resolveId($submissionId, $submission), $phone);

        $response->assertStatus(302)->assertRedirect(route('requests.public.lookup'))->assertSessionHas('lookup_failed', true);
        $this->assertPrivateHeaders($response);
        $this->get(route('requests.public.lookup'))->assertOk()->assertSee(self::GENERIC_MESSAGE);
        $this->get(route('requests.public.lookup'))->assertOk()->assertDontSee(self::GENERIC_MESSAGE);
    }

    public function test_failures_are_indistinguishable_in_body_headers_and_queries(): void
    {
        $submission = $this->publicRequest();
        $internal = $this->publicRequest(['public_token' => null, 'requester_phone' => self::PHONE]);
        $cases = [
            'id tidak ada' => [$submission->id + 500, self::PHONE],
            'nomor salah' => [$submission->id, '081399990000'],
            'format salah' => ['abc', 'abc'],
            'bukan pengajuan publik' => [$internal->id, self::PHONE],
        ];

        $seen = [];
        foreach ($cases as $name => [$number, $phone]) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->lookup($number, $phone);
            $queries = array_map(fn (array $query): string => $query['query'], DB::getQueryLog());
            DB::disableQueryLog();

            $headers = $response->headers->all();
            unset($headers['date'], $headers['set-cookie'], $headers['x-ratelimit-remaining']);
            $seen[$name] = ['status' => $response->getStatusCode(), 'body' => $response->getContent(), 'headers' => $headers, 'queries' => $queries];
        }

        $first = array_shift($seen);
        $this->assertCount(1, $first['queries'], 'Pencarian gagal harus menjalankan satu kueri saja.');
        foreach ($seen as $name => $result) {
            $this->assertSame($first, $result, "Respons kegagalan \"{$name}\" berbeda dari kegagalan lain.");
        }
    }

    public function test_failed_lookup_never_echoes_input_or_the_stored_phone(): void
    {
        $submission = $this->publicRequest();

        $this->lookup($submission->id, '081399990000')->assertRedirect(route('requests.public.lookup'));
        $html = $this->get(route('requests.public.lookup'))->assertSee(self::GENERIC_MESSAGE)->getContent();

        $this->assertStringNotContainsString('081399990000', $html);
        $this->assertStringNotContainsString('81355550000', $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);
    }

    public function test_lookup_is_throttled_to_six_attempts_per_minute_per_ip(): void
    {
        $submission = $this->publicRequest();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->lookup($submission->id, '081399990000')->assertStatus(302);
        }

        $this->lookup($submission->id, '081399990000')->assertStatus(429);
        $this->lookup($submission->id, self::PHONE)->assertStatus(429);
        $this->get(route('requests.public.lookup'))->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->lookup($submission->id, self::PHONE)->assertOk();
    }

    public function test_validation_errors_tell_applicants_to_choose_the_attachments_again(): void
    {
        DocumentType::factory()->create();

        $this->get(route('requests.public.create'))->assertOk()->assertDontSee('Pilih kembali berkas lampiran');

        $this->post(route('requests.public.store'), ['requester_name' => ''])
            ->assertRedirect(route('requests.public.create'));

        $this->get(route('requests.public.create'))->assertOk()
            ->assertSee('Periksa kembali isian pengajuan.')->assertSee('Nama pengaju wajib diisi.')->assertDontSee('Unggah berkas lampiran.')
            ->assertSee('Pilih kembali berkas lampiran');
    }

    public function test_the_attachment_notice_also_appears_on_the_revision_page(): void
    {
        $this->publicRequest(['status' => 'revision']);
        $url = route('requests.public.status', ['token' => self::TOKEN]);

        $this->get($url)->assertOk()->assertDontSee('Pilih kembali berkas lampiran');

        $this->post($url, ['requester_name' => '', 'title' => ''])->assertRedirect($url);

        $this->get($url)->assertOk()
            ->assertSee('Judul pengajuan wajib diisi.')->assertSee('Kirim revisi')->assertSee('Pilih kembali berkas lampiran');
    }

    private function assertPrivateHeaders(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }
}
