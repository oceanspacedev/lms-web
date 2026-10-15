<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DummyAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DummyAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    private function dummyUser(string $role): User
    {
        return User::where('email', str_replace('_', '-', $role).'@'.DummyAccountSeeder::EMAIL_DOMAIN)->firstOrFail();
    }

    public function test_every_role_gets_one_dummy_account_that_can_open_the_panel(): void
    {
        Role::findOrCreate('custom_role_buatan_sendiri', 'web');

        $this->seed(DummyAccountSeeder::class);

        $roles = Role::pluck('name');
        $this->assertTrue($roles->contains('super_admin'));
        $this->assertTrue($roles->contains('custom_role_buatan_sendiri'));
        $this->assertSame($roles->count(), User::count());
        foreach ($roles as $role) {
            $user = $this->dummyUser($role);
            $this->assertSame([$role], $user->getRoleNames()->all());
            $this->assertTrue(Hash::check(DummyAccountSeeder::PASSWORD, $user->password));
            $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
            $this->assertNull($user->phone);
        }
    }

    public function test_dummy_account_names_do_not_repeat_the_dummy_prefix(): void
    {
        $this->seed(DummyAccountSeeder::class);

        $this->assertSame('Dummy Pemeriksa', $this->dummyUser('dummy_pemeriksa')->name);
        $this->assertSame('Dummy Super Admin', $this->dummyUser('super_admin')->name);
    }

    public function test_seeder_is_idempotent_and_resets_the_dummy_password(): void
    {
        $this->seed(DummyAccountSeeder::class);
        $count = User::count();
        $this->dummyUser('dummy_viewer')->update(['password' => 'diganti-orang']);

        $this->seed(DummyAccountSeeder::class);

        $this->assertSame($count, User::count());
        $this->assertSame($count, Role::count());
        $this->assertTrue(Hash::check(DummyAccountSeeder::PASSWORD, $this->dummyUser('dummy_viewer')->password));
    }

    public function test_baseline_roles_match_the_request_workflow(): void
    {
        $this->seed(DummyAccountSeeder::class);

        $viewer = $this->dummyUser('dummy_viewer');
        $this->assertTrue($viewer->can('ViewAny:Document'));
        $this->assertFalse($viewer->can('Create:Document'));

        $reviewer = $this->dummyUser('dummy_pemeriksa');
        $this->assertTrue($reviewer->can('Review:DocumentRequest'));
        $this->assertFalse($reviewer->can('Approve:DocumentRequest'));

        $approver = $this->dummyUser('dummy_penyetuju');
        $this->assertTrue($approver->can('Approve:DocumentRequest'));
        $this->assertTrue($approver->can('Create:Document'));
        $this->assertFalse($approver->can('Review:DocumentRequest'));

        $requester = $this->dummyUser('dummy_pengaju');
        $this->assertTrue($requester->can('Create:DocumentRequest'));
        $this->assertFalse($requester->can('Review:DocumentRequest'));

        $legal = $this->dummyUser('dummy_legal');
        $this->assertTrue($legal->can('Update:Document'));
        $this->assertTrue($legal->can('receive_reminder'));
        $this->assertFalse($legal->can('Create:User'));

        $this->assertTrue($this->dummyUser('super_admin')->can('Create:User'));
    }

    public function test_existing_custom_role_permissions_are_not_overwritten(): void
    {
        $custom = Role::findOrCreate('legal', 'web');
        $custom->givePermissionTo(Permission::findOrCreate('View:Company', 'web'));

        $this->seed(DummyAccountSeeder::class);

        $this->assertSame(['View:Company'], $custom->fresh()->permissions->pluck('name')->all());
    }

    public function test_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->app->make(DummyAccountSeeder::class)->run();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Role::count());
    }
}
