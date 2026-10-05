<?php

namespace Tests\Feature;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(CompanySeeder::class);
    }

    private function signInWithPermissions(array $permissions): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);
    }

    public function test_authorized_user_can_create_company_with_optional_fields(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Create:Company']);
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'PT Contoh Legal', 'legal_form' => 'PT', 'npwp' => null, 'address' => null, 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('companies', ['name' => 'PT Contoh Legal', 'legal_form' => 'PT', 'npwp' => null, 'address' => null, 'is_active' => true]);
    }

    public function test_required_fields_and_unique_name_are_validated(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Create:Company']);
        $company = Company::factory()->create();
        Livewire::test(CreateCompany::class)->fillForm(['name' => '', 'legal_form' => ''])
            ->call('create')->assertHasFormErrors(['name' => 'required', 'legal_form' => 'required']);
        Livewire::test(CreateCompany::class)->fillForm(['name' => $company->name, 'legal_form' => 'CV'])
            ->call('create')->assertHasFormErrors(['name' => 'unique']);
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_company_can_be_updated_and_deactivated_without_deletion(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Update:Company']);
        $company = Company::factory()->create();
        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm(['name' => $company->name, 'npwp' => '12.345.678.9-012.000', 'address' => 'Jakarta', 'is_active' => false])
            ->call('save')->assertHasNoFormErrors()->assertActionDoesNotExist('delete');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'address' => 'Jakarta', 'is_active' => false]);
        $this->assertFalse($company->fresh()->is_active);
        $this->assertFalse(CompanyResource::canDelete($company));
        $this->assertFalse(CompanyResource::canDeleteAny());
    }

    public function test_edit_cannot_take_another_company_name(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Update:Company']);
        $companies = Company::factory()->count(2)->create();
        Livewire::test(EditCompany::class, ['record' => $companies[0]->getRouteKey()])
            ->fillForm(['name' => $companies[1]->name])->call('save')->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_search_and_status_filter_show_matching_companies(): void
    {
        $this->signInWithPermissions(['ViewAny:Company']);
        $active = Company::factory()->create(['name' => 'PT Aktif']);
        $inactive = Company::factory()->inactive()->create(['name' => 'CV Arsip']);
        Livewire::test(ListCompanies::class)->assertCanSeeTableRecords([$active, $inactive])
            ->searchTable('Arsip')->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active])
            ->searchTable('')->filterTable('is_active', true)->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$inactive])
            ->filterTable('is_active', false)->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active]);
    }

    public function test_user_without_permissions_cannot_access_resource_or_navigation(): void
    {
        $this->actingAs(User::factory()->create());
        $company = Company::factory()->create();
        $this->assertFalse(CompanyResource::canAccess());
        Livewire::test(ListCompanies::class)->assertForbidden();
        Livewire::test(CreateCompany::class)->assertForbidden();
        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])->assertForbidden();
    }

    public function test_viewer_can_list_but_cannot_create_or_edit(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'View:Company']);
        $company = Company::factory()->create();
        Livewire::test(ListCompanies::class)->assertSuccessful()->assertCanSeeTableRecords([$company])->assertActionHidden('create');
        Livewire::test(CreateCompany::class)->assertForbidden();
        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])->assertForbidden();
    }

    public function test_permissions_can_be_seeded_repeatedly(): void
    {
        $this->seed(CompanySeeder::class);
        $this->assertDatabaseCount('permissions', 12);
        $this->assertDatabaseHas('permissions', ['name' => 'Update:Company', 'guard_name' => 'web']);
    }
}
