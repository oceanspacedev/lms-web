<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Filament\Resources\DocumentTypes\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\EditDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\ListDocumentTypes;
use App\Models\DocumentType;
use App\Models\ReminderTemplate;
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

    public function test_create_stores_expiry_flag_and_has_no_per_type_reminder_field(): void
    {
        $this->signInWithPermissions(['ViewAny:DocumentType', 'Create:DocumentType']);
        Livewire::test(CreateDocumentType::class)
            ->assertFormFieldDoesNotExist('reminder_days')
            ->fillForm(['name' => 'Perjanjian Baru', 'has_expiry' => true, 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();

        $documentType = DocumentType::where('name', 'Perjanjian Baru')->sole();
        $this->assertSame([], $documentType->reminder_days);
        $this->assertTrue($documentType->has_expiry);
        $this->assertTrue($documentType->is_active);
    }

    public function test_reminder_schedule_comes_from_the_global_template_for_expiring_types_only(): void
    {
        $expiring = DocumentType::factory()->create(['has_expiry' => true]);
        $withoutExpiry = DocumentType::factory()->withoutExpiry()->create();
        $this->assertSame([], $expiring->effectiveReminderDays());

        $template = ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => ['7', '60', '30']]);
        $this->assertSame([60, 30, 7], $expiring->effectiveReminderDays());
        $this->assertSame([], $withoutExpiry->effectiveReminderDays());

        $template->update(['is_active' => false]);
        $this->assertSame([], $expiring->effectiveReminderDays());
    }

    public function test_create_without_expiry_does_not_store_reminders(): void
    {
        $this->signInWithPermissions(['ViewAny:DocumentType', 'Create:DocumentType']);
        Livewire::test(CreateDocumentType::class)
            ->fillForm(['name' => 'Akta Baru', 'has_expiry' => false, 'reminder_days' => ['90', '30'], 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame([], DocumentType::where('name', 'Akta Baru')->sole()->reminder_days);
    }

    #[DataProvider('invalidLegacyReminderDays')]
    public function test_legacy_reminder_days_on_the_model_are_still_validated(array $days): void
    {
        $this->expectException(ValidationException::class);
        DocumentType::factory()->create(['has_expiry' => true, 'reminder_days' => $days]);
    }

    public static function invalidLegacyReminderDays(): array
    {
        return [
            'zero' => [[0]],
            'negative' => [[-7]],
            'decimal' => [['1.5']],
            'text' => [['besok']],
            'duplicate' => [[30, 30]],
            'duplicate numeric strings' => [[30, '30']],
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

    public function test_edit_keeps_existing_legacy_reminder_days_untouched(): void
    {
        $this->signInWithPermissions(['ViewAny:DocumentType', 'Update:DocumentType']);
        $type = DocumentType::factory()->create(['reminder_days' => [90, 60, 30]]);
        Livewire::test(EditDocumentType::class, ['record' => $type->getRouteKey()])
            ->assertFormFieldDoesNotExist('reminder_days')
            ->fillForm(['name' => 'Nama Diubah'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Nama Diubah', $type->fresh()->name);
        $this->assertSame([90, 60, 30], $type->fresh()->reminder_days);
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
