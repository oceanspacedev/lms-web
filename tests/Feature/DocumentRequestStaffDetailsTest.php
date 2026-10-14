<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DummyAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Pemeriksa dan penyetuju membuka pengajuan yang tidak bisa mereka ubah (halaman hanya-baca); data yang diisi pengaju
 * (tanggal, biaya, mitra, dan isian tambahan jenis dokumen) harus tetap terbaca di sana.
 */
class DocumentRequestStaffDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    /** @param list<string> $permissions */
    private function signInAsReviewer(array $permissions = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'ViewAll:DocumentRequest', 'Review:DocumentRequest']): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['name' => 'Rina Pemeriksa']);
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    /** @return list<array{key: string, label: string, type: string, required: bool}> */
    private function fields(): array
    {
        return [
            ['key' => 'k-direktur', 'label' => 'Nama Direktur', 'type' => 'text', 'required' => true],
            ['key' => 'k-lingkup', 'label' => 'Ruang Lingkup', 'type' => 'textarea', 'required' => true],
            ['key' => 'k-rapat', 'label' => 'Tanggal Rapat', 'type' => 'date', 'required' => false],
            ['key' => 'k-proyek', 'label' => 'Nilai Proyek', 'type' => 'number', 'required' => false],
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function request(array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->create([
            'status' => 'submitted', 'requester_id' => User::factory()->create(['name' => 'Budi Pengaju'])->id, 'title' => 'Kerja Sama Distribusi', 'partner_name' => 'PT Mitra Sejati',
            'requirements' => ['fields' => [], 'attachments' => [], 'has_expiry' => false],
            'history' => [['status' => 'submitted', 'user' => 'Budi Pengaju', 'user_id' => null, 'at' => now()->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    public function test_reviewer_reads_the_extra_fields_a_guest_filled_in_on_the_public_form(): void
    {
        $this->seed(DummyAccountSeeder::class);
        $reviewer = User::where('email', 'dummy-pemeriksa@'.DummyAccountSeeder::EMAIL_DOMAIN)->firstOrFail();
        $reviewer->update(['phone' => '081200000001']);
        $company = Company::factory()->create(['name' => 'PT Alur Lengkap', 'is_active' => true]);
        $type = DocumentType::factory()->withoutExpiry()->create([
            'name' => 'Perjanjian Kerja Sama', 'request_pic_id' => $reviewer->id, 'request_reviewer_id' => $reviewer->id,
            'request_fields' => [
                ['label' => 'Nama Direktur', 'type' => 'text', 'required' => true],
                ['label' => 'Ruang Lingkup', 'type' => 'textarea', 'required' => true],
                ['label' => 'Tanggal Rapat', 'type' => 'date', 'required' => false],
                ['label' => 'Nilai Proyek', 'type' => 'number', 'required' => false],
            ],
        ]);
        $keys = collect($type->fresh()->request_fields)->pluck('key', 'label');

        $this->post(route('requests.public.store'), [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional', 'request_reason' => 'Kerja sama distribusi',
            'title' => 'Kerja Sama Distribusi', 'document_type_id' => $type->id, 'company_id' => $company->id,
            'attachments' => ['supporting-document' => UploadedFile::fake()->create('pendukung.pdf', 10, 'application/pdf')],
            'details' => [$keys['Nama Direktur'] => 'Pak Direktur Unik', $keys['Ruang Lingkup'] => "Distribusi produk\nKe seluruh Jawa", $keys['Tanggal Rapat'] => '2026-11-02', $keys['Nilai Proyek'] => '75000'],
        ])->assertRedirect();
        $request = DocumentRequest::latest('id')->firstOrFail();
        $this->assertNull($request->requester_id);

        $page = Livewire::actingAs($reviewer)->test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('Isian tambahan')
            ->assertSeeInOrder(['Nama Direktur', 'Pak Direktur Unik', 'Ruang Lingkup', 'Distribusi produk', 'Tanggal Rapat', '02 Nov 2026', 'Nilai Proyek', '75000']);
        $this->assertStringContainsString("Distribusi produk\nKe seluruh Jawa", $page->html());
        $this->assertStringContainsString('white-space: pre-line', $page->html());
    }

    public function test_extra_fields_without_an_answer_are_listed_as_not_filled_in(): void
    {
        $this->signInAsReviewer();
        $request = $this->request(['start_date' => '2026-11-01', 'requirements' => ['fields' => $this->fields(), 'attachments' => [], 'has_expiry' => false], 'details' => ['k-direktur' => 'Siti Aminah', 'k-lingkup' => 'Distribusi']]);

        $page = Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('Isian tambahan')->assertSee('Siti Aminah')->assertSee('Tanggal Rapat')->assertSee('Nilai Proyek');

        $this->assertSame(2, substr_count(strip_tags($page->html()), 'Belum diisi'));
    }

    public function test_extra_field_values_are_escaped(): void
    {
        $this->signInAsReviewer();
        $request = $this->request(['requirements' => ['fields' => $this->fields(), 'attachments' => [], 'has_expiry' => false], 'details' => ['k-direktur' => '<script>alert(1)</script>']]);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_extra_fields_section_is_hidden_for_a_request_without_extra_fields(): void
    {
        $this->signInAsReviewer();
        $request = $this->request();

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->assertSee('Rincian pengajuan')->assertDontSee('Isian tambahan');
    }

    public function test_reviewer_sees_dates_cost_and_partner_contact_of_an_internal_request(): void
    {
        $this->signInAsReviewer();
        $request = $this->request([
            'purpose' => 'renewal', 'start_date' => '2026-11-01', 'expiry_date' => '2027-10-31', 'target_date' => '2026-10-20', 'partner_pic' => 'Ibu Wulan', 'partner_contact' => 'wulan@mitra.test',
            'has_cost' => true, 'amount' => '1234567.50', 'payment_terms' => "Termin 1: 50%\nTermin 2: 50%",
        ]);

        $page = Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('Rincian pengajuan')
            ->assertSeeInOrder(['Tujuan pengajuan', 'Perpanjangan', 'Mulai', '01 Nov 2026', 'Berakhir', '31 Okt 2027', 'Target selesai', '20 Okt 2026'])
            ->assertSee('PIC mitra')->assertSee('Ibu Wulan')->assertSee('Kontak mitra')->assertSee('wulan@mitra.test')
            ->assertSeeInOrder(['Biaya', 'Ada biaya', 'Nilai', 'Rp 1.234.567,50', 'Pembayaran', 'Termin 1: 50%']);
        $this->assertStringContainsString("Termin 1: 50%\nTermin 2: 50%", $page->html());
    }

    public function test_request_without_cost_or_optional_data_shows_only_what_applies(): void
    {
        $this->signInAsReviewer();
        $request = $this->request(['purpose' => 'new', 'start_date' => '2026-11-01', 'has_cost' => false]);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('Rincian pengajuan')->assertSee('Baru')->assertSee('Tanpa biaya')->assertSee('01 Nov 2026')
            ->assertDontSee('Pembayaran')->assertDontSee('PIC mitra')->assertDontSee('Kontak mitra')->assertDontSee('Target selesai')->assertDontSee('Berakhir');
    }

    public function test_missing_start_expiry_and_cost_values_are_marked_for_the_reviewer(): void
    {
        $this->signInAsReviewer();
        $request = $this->request(['start_date' => null, 'expiry_date' => null, 'has_cost' => true, 'amount' => null, 'payment_terms' => null, 'requirements' => ['fields' => [], 'attachments' => [], 'has_expiry' => true]]);

        $page = Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->assertSee('Berakhir')->assertSee('Nilai')->assertSee('Pembayaran');

        $this->assertSame(4, substr_count(strip_tags($page->html()), 'Belum diisi'));
    }

    public function test_public_request_does_not_show_purpose_dates_or_cost_that_the_guest_was_never_asked_for(): void
    {
        $this->signInAsReviewer();
        $request = $this->request([
            'requester_id' => null, 'requester_name' => 'Budi Publik', 'requester_phone' => '6281355550000', 'public_token' => str_repeat('b', 64),
            'purpose' => 'new', 'has_cost' => false, 'start_date' => null, 'expiry_date' => null,
            'requirements' => ['fields' => [], 'attachments' => [], 'has_expiry' => true],
        ]);

        $page = Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('Rincian pengajuan')
            ->assertSee('Diajukan lewat form publik: tujuan pengajuan, tanggal mulai/berakhir, dan biaya tidak ditanyakan kepada pengaju.')
            ->assertDontSee('Tujuan pengajuan')->assertDontSee('Tanpa biaya')->assertDontSee('Berakhir');

        $this->assertStringNotContainsString('Belum diisi', strip_tags($page->html()));
        $this->assertStringNotContainsString('Mulai', strip_tags($page->html()));
    }

    public function test_public_request_still_shows_values_that_staff_filled_in_later(): void
    {
        $this->signInAsReviewer();
        $request = $this->request([
            'requester_id' => null, 'requester_name' => 'Budi Publik', 'requester_phone' => '6281355550000', 'public_token' => str_repeat('c', 64),
            'purpose' => 'renewal', 'has_cost' => true, 'amount' => '500000', 'payment_terms' => 'Lunas', 'start_date' => '2026-11-01', 'expiry_date' => null,
            'requirements' => ['fields' => [], 'attachments' => [], 'has_expiry' => true],
        ]);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSeeInOrder(['Tujuan pengajuan', 'Perpanjangan', 'Mulai', '01 Nov 2026'])
            ->assertSeeInOrder(['Biaya', 'Ada biaya', 'Nilai', 'Rp 500.000'])
            ->assertDontSee('Berakhir');
    }

    public function test_amounts_up_to_the_form_limit_keep_every_digit(): void
    {
        $this->signInAsReviewer();
        $request = $this->request(['has_cost' => true, 'amount' => '9999999999999999', 'payment_terms' => 'Lunas']);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->assertSee('Rp 9.999.999.999.999.999');
    }

    public function test_requester_editing_their_own_draft_does_not_get_the_read_only_sections(): void
    {
        $user = $this->signInAsReviewer(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Update:DocumentRequest']);
        $draft = $this->request(['status' => 'draft', 'requester_id' => $user->id, 'requirements' => ['fields' => $this->fields(), 'attachments' => [], 'has_expiry' => false]]);

        Livewire::test(EditDocumentRequest::class, ['record' => $draft->getRouteKey()])
            ->assertDontSee('Rincian pengajuan')->assertDontSee('Isian tambahan')->assertSee('Nama Direktur')->assertSee('Simpan Draf');
    }
}
