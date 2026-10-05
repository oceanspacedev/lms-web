<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminMonitoringAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_monitoring_links_from_the_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);

        $items = collect(Filament::getPanel('admin')->getNavigationItems())->keyBy(fn ($item): string => $item->getLabel());

        foreach (['Horizon' => 'horizon.index', 'Log Laravel' => 'log-viewer.index'] as $label => $routeName) {
            $item = $items->get($label);
            $this->assertNotNull($item);
            $this->assertTrue($item->isVisible());
            $this->assertTrue($item->shouldOpenUrlInNewTab());
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
}
