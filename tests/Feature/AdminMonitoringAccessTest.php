<?php

namespace Tests\Feature;

use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole;
use Database\Seeders\MonitoringPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminMonitoringAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(MonitoringPermissionSeeder::class);
    }

    public function test_super_admin_can_open_monitoring_links_from_the_panel(): void
    {
        $user = User::factory()->create();
        $role = Role::findOrCreate('super_admin', 'web');
        $role->givePermissionTo(['View:Horizon', 'View:LogViewer']);
        $user->assignRole($role);
        $this->actingAs($user);

        $items = collect(Filament::getPanel('admin')->getNavigationItems())->keyBy(fn ($item): string => $item->getLabel());

        foreach (['Horizon' => 'horizon.index', 'Log Laravel' => 'log-viewer.index'] as $label => $routeName) {
            $item = $items->get($label);
            $this->assertNotNull($item);
            $this->assertTrue($item->isVisible());
            $this->assertTrue($item->shouldOpenUrlInNewTab());
            $this->assertSame(route($routeName), $item->getUrl());
            $this->get($item->getUrl())->assertOk();
        }
    }

    public function test_regular_users_and_guests_cannot_access_monitoring(): void
    {
        $items = Filament::getPanel('admin')->getNavigationItems();

        foreach ($items as $item) {
            $this->assertFalse($item->isVisible());
        }

        $this->get(route('horizon.index'))->assertForbidden();
        $this->get(route('log-viewer.index'))->assertForbidden();

        $this->actingAs(User::factory()->create());

        foreach ($items as $item) {
            $this->assertFalse($item->isVisible());
        }

        $this->get(route('horizon.index'))->assertForbidden();
        $this->get(route('log-viewer.index'))->assertForbidden();
    }

    public function test_shield_custom_checkboxes_enable_and_disable_each_monitoring_menu(): void
    {
        foreach (['ViewAny:Role', 'Update:Role'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $editor = User::factory()->create();
        $editor->givePermissionTo(['ViewAny:Role', 'Update:Role']);
        $role = Role::findOrCreate('monitoring', 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        $items = collect(Filament::getPanel('admin')->getNavigationItems())->keyBy(fn ($item): string => $item->getLabel());

        $this->actingAs($editor);
        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertSee('Horizon')->assertSee('Log Laravel')
            ->fillForm(['custom_permissions_tab' => ['View:Horizon']])
            ->call('save')->assertHasNoFormErrors();

        $this->actingAs($user->fresh());
        $this->assertTrue($items['Horizon']->isVisible());
        $this->assertFalse($items['Log Laravel']->isVisible());
        $this->get(route('horizon.index'))->assertOk();
        $this->get(route('log-viewer.index'))->assertForbidden();

        $this->actingAs($editor);
        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['custom_permissions_tab' => ['View:LogViewer']])
            ->call('save')->assertHasNoFormErrors();

        $this->actingAs($user->fresh());
        $this->assertFalse($items['Horizon']->isVisible());
        $this->assertTrue($items['Log Laravel']->isVisible());
        $this->get(route('horizon.index'))->assertForbidden();
        $this->get(route('log-viewer.index'))->assertOk();

        $this->actingAs($editor);
        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['custom_permissions_tab' => []])
            ->call('save')->assertHasNoFormErrors();

        $this->actingAs($user->fresh());
        $this->assertFalse($items['Horizon']->isVisible());
        $this->assertFalse($items['Log Laravel']->isVisible());
        $this->get(route('horizon.index'))->assertForbidden();
        $this->get(route('log-viewer.index'))->assertForbidden();
    }

    public function test_super_admin_without_monitoring_permissions_is_denied_even_locally(): void
    {
        $this->app['env'] = 'local';
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);

        foreach (Filament::getPanel('admin')->getNavigationItems() as $item) {
            $this->assertFalse($item->isVisible());
        }

        $this->get(route('horizon.index'))->assertForbidden();
        $this->get(route('log-viewer.index'))->assertForbidden();
    }

    public function test_custom_permission_seeder_is_idempotent(): void
    {
        $this->seed(MonitoringPermissionSeeder::class);
        $this->assertDatabaseCount('permissions', 2);
        $this->assertDatabaseHas('permissions', ['name' => 'View:Horizon', 'guard_name' => 'web']);
        $this->assertDatabaseHas('permissions', ['name' => 'View:LogViewer', 'guard_name' => 'web']);
    }
}
