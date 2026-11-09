<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\FileSize;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Hasil audit UI/UX: istilah, format tanggal/ukuran, dan tata letak aksi yang seragam di seluruh panel.
 */
class UiConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
        Storage::fake('s3');
        $this->seed(PermissionSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));
        $this->actingAs($user);

        return $user;
    }

    public function test_create_pages_say_tambah_and_save_like_the_list_buttons(): void
    {
        $this->superAdmin();

        $this->get(route('filament.admin.resources.users.create'))->assertOk()
            ->assertSee('Tambah User')->assertSee('Simpan & tambah lagi')->assertDontSee('Buat User')->assertSee('Pilih peran');
        $this->get(route('filament.admin.resources.documents.create'))->assertOk()
            ->assertSee('Tambah Dokumen')->assertSee('Pilih badan usaha')->assertSee('Pilih jenis dokumen')->assertSee('Pilih PIC')
            ->assertDontSee('Pilih salah satu opsi');
    }

    public function test_new_user_must_get_a_role_because_users_without_roles_cannot_sign_in(): void
    {
        $this->superAdmin();

        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Tanpa Peran', 'email' => 'tanpa-peran@contoh.test', 'password' => 'sandi-baru-123', 'password_confirmation' => 'sandi-baru-123',
        ])->call('create')->assertHasFormErrors(['roles' => 'required']);
        $this->assertDatabaseMissing('users', ['email' => 'tanpa-peran@contoh.test']);
    }

    public function test_role_screens_hide_the_technical_guard_name_and_use_the_menu_name(): void
    {
        $this->superAdmin();

        Livewire::test(ListRoles::class)->assertOk()->assertSee('Hak Akses')->assertDontSee('Nama Guard')->assertDontSee('Daftar Peran');
        $this->get(route('filament.admin.resources.shield.roles.create'))->assertOk()->assertSee('Tambah Peran')->assertDontSee('Nama Guard');
    }

    public function test_document_detail_shows_original_name_first_and_indonesian_size_and_dates(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 14)->setTime(3, 36));
        $document = Document::archive([
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->create()->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => 'Kontrak Format',
            'issued_date' => '2026-03-01',
            'expiry_date' => '2027-03-01',
        ], UploadedFile::fake()->create('Kontrak Asli.pdf', 1536, 'application/pdf'), User::factory()->create());
        $this->superAdmin();

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertSeeInOrder(['Nama File Asli', 'Kontrak Asli.pdf', 'Nama File Saat Diunduh', $document->currentVersion->stored_name])
            ->assertSee('1,5 MB')->assertSee('01 Mar 2026')->assertSee('01 Mar 2027')->assertDontSee('01/03/2026');
        $this->assertSame('108 B', FileSize::format(108));
    }

    public function test_calendar_shows_the_picked_date_in_indonesian_order(): void
    {
        $this->assertSame('d M Y', DatePicker::make('tanggal')->native(false)->getDisplayFormat());
        $this->assertSame('d M Y H:i', DateTimePicker::make('waktu')->native(false)->seconds(false)->getDisplayFormat());
        $this->assertSame('d M Y H:i:s', DateTimePicker::make('waktu')->native(false)->getDisplayFormat());
    }

    public function test_cancel_and_delete_live_in_the_more_menu_on_request_detail(): void
    {
        $legal = User::factory()->create();
        $legal->givePermissionTo(array_map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'), ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest', 'Delete:DocumentRequest']));
        $this->actingAs($legal);
        $request = DocumentRequest::factory()->signing(1)->create(['reviewer_id' => $legal->id]);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('Lainnya')->assertActionVisible('cancel')
            ->callAction('cancel', ['note' => 'Tidak jadi'])->assertHasNoActionErrors();
        $this->assertSame('cancelled', $request->fresh()->status);
    }
}
