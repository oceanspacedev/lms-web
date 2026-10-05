<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Filament\Resources\DocumentTypes\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\EditDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\ListDocumentTypes;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DocumentTypeResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->artisan('shield:generate', [
            '--resource' => 'DocumentTypeResource',
            '--panel' => 'admin',
            '--option' => 'permissions',
            '--no-interaction' => true,
        ])->assertExitCode(0);
    }

    private function signInWithPermissions(array $permissions): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);
    }

    public function test_create_stores_reminders_as_descending_json_integers(): void
    {
        $this->signInWithPermissions(['ViewAny:DocumentType', 'Create:DocumentType']);
        Livewire::test(CreateDocumentType::class)
            ->fillForm(['name' => 'Perjanjian Baru', 'has_expiry' => true, 'reminder_days' => ['30', '90', '60'], 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();

        $documentType = DocumentType::where('name', 'Perjanjian Baru')->sole();
        $this->assertSame([90, 60, 30], $documentType->reminder_days);
        $this->assertSame('[90,60,30]', $documentType->getRawOriginal('reminder_days'));
        $this->assertTrue($documentType->has_expiry);
        $this->assertTrue($documentType->is_active);
    }

    public function test_create_without_expiry_does_not_store_reminders(): void
    {
        $this->signInWithPermissions(['ViewAny:DocumentType', 'Create:DocumentType']);
        Livewire::test(CreateDocumentType::class)
            ->fillForm(['name' => 'Akta Baru', 'has_expiry' => false, 'reminder_days' => ['90', '30'], 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame([], DocumentType::where('name', 'Akta Baru')->sole()->reminder_days);
    }

    #[DataProvider('invalidReminderDays')]
    public function test_invalid_reminder_days_are_rejected(array $days, string $rule): void
    {
        $this->signInWithPermissions(['ViewAny:DocumentType', 'Create:DocumentType']);
        Livewire::test(CreateDocumentType::class)
            ->fillForm(['name' => 'Jenis Tidak Valid', 'has_expiry' => true, 'reminder_days' => $days])
            ->call('create')->assertHasFormErrors(['reminder_days.0' => $rule]);
        $this->assertDatabaseCount('document_types', 0);
    }

    public static function invalidReminderDays(): array
    {
        return [
            'zero' => [[0], 'min'],
            'negative' => [[-7], 'min'],
            'decimal' => [['1.5'], 'integer'],
            'text' => [['besok'], 'integer'],
            'duplicate' => [[30, 30], 'distinct'],
            'duplicate numeric strings' => [[30, '30'], 'distinct'],
        ];
    }

    public function test_name_is_required_and_unique_on_create_and_edit(): void
    {
        $this->signInWithPermissions(['ViewAny:DocumentType', 'Create:DocumentType', 'Update:DocumentType']);
        $types = DocumentType::factory()->count(2)->create();
        Livewire::test(CreateDocumentType::class)->fillForm(['name' => ''])
            ->call('create')->assertHasFormErrors(['name' => 'required']);
        Livewire::test(CreateDocumentType::class)->fillForm(['name' => $types[0]->name])
            ->call('create')->assertHasFormErrors(['name' => 'unique']);
        Livewire::test(EditDocumentType::class, ['record' => $types[0]->getRouteKey()])
            ->fillForm(['name' => $types[1]->name])->call('save')->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_edit_preserves_name_and_sorts_new_reminders(): void
    {
        $this->signInWithPermissions(['ViewAny:DocumentType', 'Update:DocumentType']);
        $type = DocumentType::factory()->create();
        Livewire::test(EditDocumentType::class, ['record' => $type->getRouteKey()])
            ->fillForm(['name' => $type->name, 'reminder_days' => ['7', '60', '30']])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame([60, 30, 7], $type->fresh()->reminder_days);
    }

    public function test_disabling_expiry_clears_old_reminders_and_deactivation_keeps_record(): void
    {
        $this->signInWithPermissions(['ViewAny:DocumentType', 'Update:DocumentType']);
        $type = DocumentType::factory()->create();
        Livewire::test(EditDocumentType::class, ['record' => $type->getRouteKey()])
            ->fillForm(['has_expiry' => false, 'is_active' => false])
            ->call('save')->assertHasNoFormErrors()->assertActionDoesNotExist('delete');
        $this->assertSame([], $type->fresh()->reminder_days);
        $this->assertDatabaseHas('document_types', ['id' => $type->id, 'is_active' => false, 'has_expiry' => false]);
        $this->assertFalse(DocumentTypeResource::canDelete($type));
        $this->assertFalse(DocumentTypeResource::canDeleteAny());
    }

    public function test_search_and_filters_match_document_types(): void
    {
        $this->signInWithPermissions(['ViewAny:DocumentType']);
        $active = DocumentType::factory()->create(['name' => 'Perjanjian Aktif']);
        $inactive = DocumentType::factory()->withoutExpiry()->inactive()->create(['name' => 'Akta Arsip']);
        Livewire::test(ListDocumentTypes::class)->assertCanSeeTableRecords([$active, $inactive])
            ->searchTable('Akta')->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active])
            ->searchTable('')->filterTable('is_active', true)->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$inactive])
            ->filterTable('is_active', null)->filterTable('has_expiry', false)
            ->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active]);
    }

    public function test_shield_denies_unpermitted_users_and_limits_viewers(): void
    {
        $this->actingAs(User::factory()->create());
        $type = DocumentType::factory()->create();
        $this->assertFalse(DocumentTypeResource::canAccess());
        Livewire::test(ListDocumentTypes::class)->assertForbidden();
        Livewire::test(CreateDocumentType::class)->assertForbidden();
        Livewire::test(EditDocumentType::class, ['record' => $type->getRouteKey()])->assertForbidden();

        $this->signInWithPermissions(['ViewAny:DocumentType', 'View:DocumentType']);
        Livewire::test(ListDocumentTypes::class)->assertSuccessful()->assertCanSeeTableRecords([$type])->assertActionHidden('create');
        Livewire::test(CreateDocumentType::class)->assertForbidden();
        Livewire::test(EditDocumentType::class, ['record' => $type->getRouteKey()])->assertForbidden();
    }

    public function test_seeder_is_idempotent_and_preserves_user_configuration(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        $this->assertDatabaseCount('document_types', 3);
        $this->assertSame([90, 60, 30], DocumentType::where('name', 'Perjanjian Kerja Sama')->sole()->reminder_days);
        $this->assertSame([60, 30, 7], DocumentType::where('name', 'Izin Usaha/NIB')->sole()->reminder_days);
        $this->assertSame([], DocumentType::where('name', 'Akta Pendirian')->sole()->reminder_days);
        $this->assertFalse(DocumentType::where('name', 'Akta Pendirian')->sole()->has_expiry);

        DocumentType::where('name', 'Perjanjian Kerja Sama')->sole()->update(['reminder_days' => [14, 7], 'is_active' => false]);
        $permissionCount = Permission::count();
        $this->seed(DocumentTypeSeeder::class);
        $this->assertDatabaseCount('document_types', 3);
        $this->assertDatabaseCount('permissions', $permissionCount);
        $this->assertSame(12, Permission::where('name', 'like', '%:DocumentType')->count());
        $this->assertSame([14, 7], DocumentType::where('name', 'Perjanjian Kerja Sama')->sole()->reminder_days);
        $this->assertFalse(DocumentType::where('name', 'Perjanjian Kerja Sama')->sole()->is_active);
    }

    public function test_model_enforces_reminder_invariants_outside_the_form(): void
    {
        $type = DocumentType::factory()->create(['reminder_days' => ['7', '60', '30']]);
        $this->assertSame([60, 30, 7], $type->fresh()->reminder_days);

        $this->expectException(ValidationException::class);
        $type->update(['reminder_days' => [7, 7]]);
    }
}
