<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DummyAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Isian tambahan" yang diatur per jenis dokumen tampil di form publik, divalidasi, dan tersimpan pada pengajuan.
 */
class PublicRequestExtraFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->seed(DummyAccountSeeder::class);
        $this->reviewer = User::where('email', 'dummy-pemeriksa@'.DummyAccountSeeder::EMAIL_DOMAIN)->firstOrFail();
        $this->reviewer->update(['phone' => '081200000001']);
        $this->company = Company::factory()->create(['name' => 'PT Alur Lengkap', 'is_active' => true]);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, mixed>>  $attachments
     */
    private function documentType(string $name, array $fields = [], array $attachments = []): DocumentType
    {
        return DocumentType::factory()->withoutExpiry()->create([
            'name' => $name, 'request_pic_id' => $this->reviewer->id, 'request_reviewer_id' => $this->reviewer->id,
            'request_fields' => $fields, 'request_attachments' => $attachments,
        ]);
    }

    /** Jenis dokumen dengan satu isian tiap tipe; kunci tiap isian dibuat otomatis saat disimpan. */
    private function typeWithFields(string $name = 'Perjanjian Kerja Sama'): DocumentType
    {
        return $this->documentType($name, [
            ['label' => 'Nama Direktur', 'type' => 'text', 'required' => true],
            ['label' => 'Ruang Lingkup', 'type' => 'textarea', 'required' => true],
            ['label' => 'Tanggal Rapat', 'type' => 'date', 'required' => false],
            ['label' => 'Nilai Proyek', 'type' => 'number', 'required' => false],
        ]);
    }

    /** @return array<string, string> label => kunci */
    private function keys(DocumentType $type): array
    {
        return collect($type->fresh()->request_fields)->pluck('key', 'label')->all();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(DocumentType $type, array $overrides = []): array
    {
        return [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama distribusi', 'title' => 'Kerja Sama Distribusi', 'document_type_id' => $type->id,
            'company_id' => $this->company->id,
            'attachments' => ['supporting-document' => UploadedFile::fake()->create('pendukung.pdf', 10, 'application/pdf')],
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function submit(DocumentType $type, array $overrides = []): DocumentRequest
    {
        $this->post(route('requests.public.store'), $this->payload($type, $overrides))->assertRedirect();

        return DocumentRequest::latest('id')->firstOrFail();
    }

    /** Tag input atau textarea untuk satu isian tambahan. */
    private function fieldTag(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('/<(?:input|textarea)\b[^>]*name="details\['.preg_quote($key, '/').'\]"[^>]*>/', $html, $match), "Isian details[$key] tidak ditemukan.");

        return $match[0];
    }

    public function test_form_shows_the_extra_fields_of_each_type_with_their_type_and_required_flag(): void
    {
        $type = $this->typeWithFields();
        $keys = $this->keys($type);

        $html = $this->get(route('requests.public.create'))->assertOk()->assertSee('Isian tambahan')
            ->assertSee('Nama Direktur')->assertSee('Ruang Lingkup')->assertSee('Tanggal Rapat')->assertSee('Nilai Proyek')->getContent();

        $this->assertMatchesRegularExpression('/<section class="form-section" data-document-type="'.$type->id.'" hidden><h2>Isian tambahan<\/h2>/', $html);
        $director = $this->fieldTag($html, $keys['Nama Direktur']);
        $this->assertStringContainsString('<input', $director);
        $this->assertStringContainsString('type="text"', $director);
        $this->assertStringContainsString('data-required="1"', $director);
        $scope = $this->fieldTag($html, $keys['Ruang Lingkup']);
        $this->assertStringStartsWith('<textarea', $scope);
        $this->assertStringContainsString('data-required="1"', $scope);
        $date = $this->fieldTag($html, $keys['Tanggal Rapat']);
        $this->assertStringContainsString('type="date"', $date);
        $this->assertStringContainsString('data-required="0"', $date);
        $number = $this->fieldTag($html, $keys['Nilai Proyek']);
        $this->assertStringContainsString('type="number"', $number);
        $this->assertStringContainsString('data-required="0"', $number);
        // Wajib dan tidaknya diatur skrip sesuai jenis dokumen yang dipilih, bukan oleh atribut statis di bagian yang tersembunyi.
        $this->assertDoesNotMatchRegularExpression('/\srequired[\s>=]/', $director.$scope.$date.$number);
        $this->assertStringContainsString('<span aria-hidden="true">*</span>', $html);
    }

    public function test_each_type_gets_its_own_hidden_group_with_distinct_ids(): void
    {
        $first = $this->typeWithFields('Perjanjian Kerja Sama');
        $second = $this->documentType('Surat Kuasa', [['label' => 'Nama Penerima Kuasa', 'type' => 'text', 'required' => true]]);

        $html = $this->get(route('requests.public.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-document-type="'.$first->id.'"', $html);
        $this->assertStringContainsString('data-document-type="'.$second->id.'"', $html);
        $this->assertSame(1, preg_match_all('/id="field-details-'.$second->id.'-/', $html));
        $this->assertSame(4, preg_match_all('/id="field-details-'.$first->id.'-/', $html));
    }

    public function test_type_without_fields_or_extra_attachments_renders_no_empty_headings(): void
    {
        $this->documentType('Akta Pendirian');

        $this->get(route('requests.public.create'))->assertOk()->assertSee('Dokumen pendukung')
            ->assertDontSee('Isian tambahan')->assertDontSee('Lampiran tambahan')->assertDontSee('data-condition', false);
    }

    public function test_only_attachments_that_always_apply_are_rendered_on_the_public_form(): void
    {
        $this->documentType('Perjanjian Kerja Sama', [], [
            ['label' => 'Proposal', 'required' => true, 'when' => 'always'],
            ['label' => 'Draf MoU', 'required' => false, 'when' => 'always'],
            ['label' => 'RAB Penawaran', 'required' => true, 'when' => 'cost'],
            ['label' => 'Perjanjian Sebelumnya', 'required' => true, 'when' => 'renewal'],
        ]);

        $this->get(route('requests.public.create'))->assertOk()->assertSee('Lampiran tambahan')->assertSee('Proposal')->assertSee('Draf MoU')
            ->assertDontSee('RAB Penawaran')->assertDontSee('Perjanjian Sebelumnya')->assertDontSee('data-condition', false);
    }

    public function test_attachments_heading_is_hidden_when_only_cost_or_renewal_attachments_exist(): void
    {
        $this->documentType('Perjanjian Kerja Sama', [], [
            ['label' => 'RAB Penawaran', 'required' => true, 'when' => 'cost'],
            ['label' => 'Perjanjian Sebelumnya', 'required' => true, 'when' => 'renewal'],
        ]);

        $this->get(route('requests.public.create'))->assertOk()->assertSee('Dokumen pendukung')
            ->assertDontSee('Lampiran tambahan')->assertDontSee('RAB Penawaran')->assertDontSee('Perjanjian Sebelumnya');
    }

    public function test_public_requirements_snapshot_contains_the_fields_of_the_type(): void
    {
        $type = $this->typeWithFields();
        $keys = $this->keys($type);

        $request = $this->submit($type, ['details' => [$keys['Nama Direktur'] => 'Siti', $keys['Ruang Lingkup'] => 'Distribusi']]);

        $this->assertSame([
            ['key' => $keys['Nama Direktur'], 'label' => 'Nama Direktur', 'type' => 'text', 'required' => true],
            ['key' => $keys['Ruang Lingkup'], 'label' => 'Ruang Lingkup', 'type' => 'textarea', 'required' => true],
            ['key' => $keys['Tanggal Rapat'], 'label' => 'Tanggal Rapat', 'type' => 'date', 'required' => false],
            ['key' => $keys['Nilai Proyek'], 'label' => 'Nilai Proyek', 'type' => 'number', 'required' => false],
        ], $request->requirements['fields']);
    }

    public function test_submitted_details_are_stored_on_the_request(): void
    {
        $type = $this->typeWithFields();
        $keys = $this->keys($type);

        $request = $this->submit($type, ['details' => [
            $keys['Nama Direktur'] => 'Siti Aminah', $keys['Ruang Lingkup'] => 'Distribusi produk', $keys['Tanggal Rapat'] => '2026-11-02', $keys['Nilai Proyek'] => '1500000.50',
        ]]);

        $this->assertSame('submitted', $request->status);
        $this->assertSame([
            $keys['Nama Direktur'] => 'Siti Aminah', $keys['Ruang Lingkup'] => 'Distribusi produk', $keys['Tanggal Rapat'] => '2026-11-02', $keys['Nilai Proyek'] => '1500000.50',
        ], $request->details);
    }

    public function test_optional_fields_may_stay_empty(): void
    {
        $type = $this->typeWithFields();
        $keys = $this->keys($type);

        $request = $this->submit($type, ['details' => [$keys['Nama Direktur'] => 'Siti', $keys['Ruang Lingkup'] => 'Distribusi', $keys['Tanggal Rapat'] => '', $keys['Nilai Proyek'] => '']]);

        $this->assertSame('Siti', $request->details[$keys['Nama Direktur']]);
        $this->assertNull($request->details[$keys['Tanggal Rapat']]);
        $this->assertNull($request->details[$keys['Nilai Proyek']]);
    }

    public function test_empty_required_field_is_rejected_with_its_message_and_nothing_is_saved(): void
    {
        $type = $this->typeWithFields();
        $keys = $this->keys($type);

        $this->post(route('requests.public.store'), $this->payload($type, ['details' => [$keys['Nama Direktur'] => '', $keys['Ruang Lingkup'] => 'Distribusi']]))
            ->assertRedirect(route('requests.public.create'))
            ->assertSessionHasErrors(['details.'.$keys['Nama Direktur'] => 'Nama Direktur wajib diisi.']);

        $this->assertSame(0, DocumentRequest::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_missing_details_array_rejects_every_required_field(): void
    {
        $type = $this->typeWithFields();
        $keys = $this->keys($type);

        $this->post(route('requests.public.store'), $this->payload($type))
            ->assertSessionHasErrors(['details.'.$keys['Nama Direktur'], 'details.'.$keys['Ruang Lingkup']])
            ->assertSessionDoesntHaveErrors(['details.'.$keys['Tanggal Rapat'], 'details.'.$keys['Nilai Proyek']]);
    }

    public function test_invalid_date_and_number_are_rejected(): void
    {
        $type = $this->typeWithFields();
        $keys = $this->keys($type);

        $this->post(route('requests.public.store'), $this->payload($type, ['details' => [
            $keys['Nama Direktur'] => 'Siti', $keys['Ruang Lingkup'] => 'Distribusi', $keys['Tanggal Rapat'] => 'besok', $keys['Nilai Proyek'] => 'banyak',
        ]]))->assertSessionHasErrors([
            'details.'.$keys['Tanggal Rapat'] => 'Tanggal Rapat harus berupa tanggal.',
            'details.'.$keys['Nilai Proyek'] => 'Nilai Proyek harus berupa angka.',
        ]);
    }

    public function test_error_is_shown_under_the_right_field_and_the_other_input_is_kept(): void
    {
        $type = $this->typeWithFields();
        $keys = $this->keys($type);
        $field = 'field-details-'.$type->id.'-'.$keys['Nama Direktur'];

        $html = $this->followingRedirects()->post(route('requests.public.store'), $this->payload($type, [
            'title' => 'Judul yang sudah diketik', 'partner_name' => 'PT Mitra Sejahtera',
            'details' => [$keys['Nama Direktur'] => '', $keys['Ruang Lingkup'] => 'Ruang lingkup yang sudah diketik', $keys['Tanggal Rapat'] => '2026-11-02', $keys['Nilai Proyek'] => '75'],
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<small class="field-error" id="'.preg_quote($field, '/').'-error">Nama Direktur wajib diisi\.<\/small>/', $html);
        $this->assertStringContainsString('id="'.$field.'" name="details['.$keys['Nama Direktur'].']"', $html);
        $this->assertMatchesRegularExpression('/aria-invalid="true"\s+aria-describedby="'.preg_quote($field, '/').'-error"/', $this->fieldTag($html, $keys['Nama Direktur']));
        $this->assertSame(1, substr_count($html, 'class="field-error"'));
        $this->assertStringContainsString('value="Judul yang sudah diketik"', $html);
        $this->assertStringContainsString('value="PT Mitra Sejahtera"', $html);
        $this->assertStringContainsString('>Ruang lingkup yang sudah diketik</textarea>', $html);
        $this->assertStringContainsString('value="2026-11-02"', $this->fieldTag($html, $keys['Tanggal Rapat']));
        $this->assertStringContainsString('value="75"', $this->fieldTag($html, $keys['Nilai Proyek']));
    }

    public function test_fields_of_another_type_are_neither_accepted_nor_stored(): void
    {
        $type = $this->typeWithFields('Perjanjian Kerja Sama');
        $other = $this->documentType('Surat Kuasa', [['label' => 'Nama Penerima Kuasa', 'type' => 'text', 'required' => true]]);
        $keys = $this->keys($type);
        $otherKey = $this->keys($other)['Nama Penerima Kuasa'];

        $request = $this->submit($type, ['details' => [
            $keys['Nama Direktur'] => 'Siti', $keys['Ruang Lingkup'] => 'Distribusi', $otherKey => 'Rina', 'kunci-liar' => 'x',
        ]]);

        $this->assertSame([$keys['Nama Direktur'] => 'Siti', $keys['Ruang Lingkup'] => 'Distribusi'], $request->details);
    }

    public function test_required_field_of_another_type_does_not_block_the_submission(): void
    {
        $this->typeWithFields('Perjanjian Kerja Sama');
        $this->documentType('Surat Kuasa', [['label' => 'Nama Penerima Kuasa', 'type' => 'text', 'required' => true]]);
        $plain = $this->documentType('Akta Pendirian');

        $request = $this->submit($plain);

        $this->assertSame([], $request->details);
        $this->assertSame([], $request->requirements['fields']);
    }

    public function test_revision_page_prefills_old_details_and_keeps_the_stored_labels(): void
    {
        $type = $this->typeWithFields();
        $keys = $this->keys($type);
        $request = $this->submit($type, ['details' => [
            $keys['Nama Direktur'] => 'Siti Aminah', $keys['Ruang Lingkup'] => 'Distribusi produk', $keys['Tanggal Rapat'] => '2026-11-02', $keys['Nilai Proyek'] => '75',
        ]]);
        $request->forceFill(['status' => 'revision'])->save();
        // Pengaturan jenis dokumen berubah setelah pengajuan dikirim; halaman revisi tetap memakai isian yang tersimpan pada pengajuan.
        $type->update(['request_fields' => [['label' => 'Isian Baru', 'type' => 'text', 'required' => true]]]);

        $html = $this->get(route('requests.public.status', ['token' => $request->public_token]))->assertOk()
            ->assertSee('Revisi pengajuan #'.$request->id)->assertSee('Nama Direktur')->assertDontSee('Isian Baru')->getContent();

        $this->assertStringContainsString('value="Siti Aminah"', $this->fieldTag($html, $keys['Nama Direktur']));
        $this->assertStringContainsString('>Distribusi produk</textarea>', $html);
        $this->assertStringContainsString('value="2026-11-02"', $this->fieldTag($html, $keys['Tanggal Rapat']));
        $this->assertStringContainsString('value="75"', $this->fieldTag($html, $keys['Nilai Proyek']));
        $this->assertSame(1, preg_match_all('/data-document-type="'.$type->id.'"/', $html));
    }

    public function test_revision_updates_the_details_and_validates_them_against_the_stored_fields(): void
    {
        $type = $this->typeWithFields();
        $keys = $this->keys($type);
        $request = $this->submit($type, ['details' => [$keys['Nama Direktur'] => 'Siti', $keys['Ruang Lingkup'] => 'Distribusi']]);
        $request->forceFill(['status' => 'revision'])->save();
        $revise = route('requests.public.revise', ['token' => $request->public_token]);
        $base = [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama distribusi', 'title' => 'Kerja Sama Distribusi',
        ];

        $this->post($revise, [...$base, 'details' => [$keys['Nama Direktur'] => '', $keys['Ruang Lingkup'] => 'Distribusi baru']])
            ->assertRedirect(route('requests.public.status', ['token' => $request->public_token]))
            ->assertSessionHasErrors(['details.'.$keys['Nama Direktur'] => 'Nama Direktur wajib diisi.']);
        $this->assertSame('revision', $request->fresh()->status);
        $this->assertSame('Siti', $request->fresh()->details[$keys['Nama Direktur']]);

        $html = $this->followingRedirects()->post($revise, [...$base, 'details' => [$keys['Nama Direktur'] => '', $keys['Ruang Lingkup'] => 'Distribusi baru']])->assertOk()->getContent();
        $this->assertStringContainsString('>Distribusi baru</textarea>', $html);
        $this->assertSame(1, substr_count($html, 'class="field-error"'));

        $this->post($revise, [...$base, 'details' => [$keys['Nama Direktur'] => 'Siti Aminah', $keys['Ruang Lingkup'] => 'Distribusi baru', 'kunci-liar' => 'x']])->assertRedirect();
        $request->refresh();
        $this->assertSame('submitted', $request->status);
        $this->assertSame([$keys['Nama Direktur'] => 'Siti Aminah', $keys['Ruang Lingkup'] => 'Distribusi baru'], $request->details);
    }

    public function test_request_stored_without_fields_behaves_as_before_even_if_the_type_has_fields_now(): void
    {
        $type = $this->documentType('Perjanjian Kerja Sama');
        $request = $this->submit($type);
        $type->update(['request_fields' => [['label' => 'Nama Direktur', 'type' => 'text', 'required' => true]]]);
        $key = $this->keys($type)['Nama Direktur'];
        $request->forceFill(['status' => 'revision'])->save();
        $this->assertSame([], $request->fresh()->requirements['fields']);

        $this->get(route('requests.public.status', ['token' => $request->public_token]))->assertOk()
            ->assertSee('Revisi pengajuan #'.$request->id)->assertDontSee('Isian tambahan')->assertDontSee('Nama Direktur');

        $this->post(route('requests.public.revise', ['token' => $request->public_token]), [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama distribusi', 'title' => 'Kerja Sama Distribusi', 'details' => [$key => 'Siti'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('submitted', $request->fresh()->status);
        $this->assertSame([], $request->fresh()->details);
    }
}
