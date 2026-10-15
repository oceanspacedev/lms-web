<?php

namespace Tests\Feature;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Schemas\CompanyForm;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

    public function test_npwp_accepts_only_fifteen_or_sixteen_digit_formats(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Create:Company']);

        Livewire::test(CreateCompany::class)->assertSeeHtml('x-mask:dynamic')
            ->fillForm(['name' => 'PT Huruf', 'legal_form' => 'PT', 'npwp' => '12.ABC.678.9-012.000'])
            ->call('create')->assertHasFormErrors(['npwp' => 'regex']);
        Livewire::test(CreateCompany::class)->fillForm(['name' => 'PT Pendek', 'legal_form' => 'PT', 'npwp' => '12345'])
            ->call('create')->assertHasFormErrors(['npwp' => 'regex']);

        foreach (['PT Lama' => '12.345.678.9-012.000', 'PT Polos' => '123456789012000', 'PT Baru' => '3201234567890001'] as $name => $npwp) {
            Livewire::test(CreateCompany::class)->fillForm(['name' => $name, 'legal_form' => 'PT', 'npwp' => $npwp])
                ->call('create')->assertHasNoFormErrors();
            $this->assertDatabaseHas('companies', ['name' => $name, 'npwp' => $npwp]);
        }
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

    public function test_legal_form_is_a_searchable_select_of_common_forms_plus_values_already_in_use(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Create:Company']);
        Company::factory()->create(['legal_form' => 'Firma Hukum']);
        Company::factory()->create(['legal_form' => 'P.T.']);
        Company::factory()->create(['legal_form' => 'PT']);

        Livewire::test(CreateCompany::class)->assertFormFieldExists('legal_form', function (Select $field): bool {
            $options = $field->getOptions();

            return $field->isSearchable() && $field->isRequired()
                && array_keys($options) === array_values($options)
                && array_diff(CompanyForm::COMMON_LEGAL_FORMS, $options) === []
                && in_array('Firma Hukum', $options, true) && in_array('P.T.', $options, true)
                && count($options) === count(array_unique($options));
        });
    }

    public function test_legal_form_is_chosen_from_the_list_and_saved(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Create:Company']);
        foreach (['Perorangan' => 'PT Orang', 'Perkumpulan' => 'PT Kumpul'] as $form => $name) {
            Livewire::test(CreateCompany::class)->fillForm(['name' => $name, 'legal_form' => $form])
                ->call('create')->assertHasNoFormErrors();
            $this->assertDatabaseHas('companies', ['name' => $name, 'legal_form' => $form]);
        }
    }

    public function test_legal_form_is_required_and_limited_to_fifty_characters(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Create:Company']);
        Livewire::test(CreateCompany::class)->fillForm(['name' => 'PT Kosong', 'legal_form' => null])
            ->call('create')->assertHasFormErrors(['legal_form' => 'required']);
        Livewire::test(CreateCompany::class)->fillForm(['name' => 'PT Panjang', 'legal_form' => str_repeat('A', 51)])
            ->call('create')->assertHasFormErrors(['legal_form' => 'max']);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_new_legal_form_can_be_added_from_the_select_with_whitespace_normalised(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Create:Company']);
        $action = TestAction::make('createOption')->schemaComponent('legal_form');

        Livewire::test(CreateCompany::class)
            ->callAction($action, ['legal_form' => "  Badan   Layanan\tUmum  "])->assertHasNoFormErrors()
            ->assertFormSet(['legal_form' => 'Badan Layanan Umum'])
            ->fillForm(['name' => 'BLU Contoh'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('companies', ['name' => 'BLU Contoh', 'legal_form' => 'Badan Layanan Umum']);

        Livewire::test(CreateCompany::class)->assertFormFieldExists('legal_form', fn (Select $field): bool => in_array('Badan Layanan Umum', $field->getOptions(), true));
    }

    public function test_adding_a_legal_form_that_already_exists_reuses_the_existing_spelling(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Create:Company']);
        $action = TestAction::make('createOption')->schemaComponent('legal_form');

        foreach (['pt', 'P.T.', ' p t ', 'Pt'] as $typed) {
            Livewire::test(CreateCompany::class)->callAction($action, ['legal_form' => $typed])
                ->assertHasNoFormErrors()->assertFormSet(['legal_form' => 'PT']);
        }
    }

    public function test_added_legal_form_is_required_and_limited_in_the_modal(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Create:Company']);
        $action = TestAction::make('createOption')->schemaComponent('legal_form');

        Livewire::test(CreateCompany::class)
            ->callAction($action, ['legal_form' => ''])->assertHasActionErrors(['legal_form' => 'required'])
            ->assertFormSet(['legal_form' => null]);
        Livewire::test(CreateCompany::class)
            ->callAction($action, ['legal_form' => str_repeat('A', 51)])->assertHasActionErrors(['legal_form' => 'max'])
            ->assertFormSet(['legal_form' => null]);
    }

    public function test_edit_keeps_a_legacy_legal_form_that_is_not_in_the_common_list(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Update:Company']);
        $company = Company::factory()->create(['legal_form' => 'P.T.']);
        Company::factory()->create(['legal_form' => 'Firma Hukum']);

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->assertFormSet(['legal_form' => 'P.T.'])
            ->assertFormFieldExists('legal_form', fn (Select $field): bool => in_array('P.T.', $field->getOptions(), true) && in_array('Firma Hukum', $field->getOptions(), true))
            ->fillForm(['address' => 'Jakarta Selatan'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'legal_form' => 'P.T.', 'address' => 'Jakarta Selatan']);
    }

    public function test_legal_form_whitespace_is_normalised_when_saved(): void
    {
        $this->signInWithPermissions(['ViewAny:Company', 'Update:Company']);
        $company = Company::factory()->create(['legal_form' => 'PT']);

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm(['legal_form' => '  Badan   Hukum  '])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Badan Hukum', $company->fresh()->legal_form);
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
        $permissionCount = Permission::count();
        $this->seed(CompanySeeder::class);
        $this->assertDatabaseCount('permissions', $permissionCount);
        $this->assertSame(12, Permission::where('name', 'like', '%:Company')->count());
        $this->assertDatabaseHas('permissions', ['name' => 'Update:Company', 'guard_name' => 'web']);
    }
}
