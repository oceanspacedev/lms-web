<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\ReminderTemplate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DummyAccountSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_exactly_the_permissions_checked_by_policies_and_custom_permissions(): void
    {
        $checkedByPolicies = collect(File::files(app_path('Policies')))
            ->flatMap(function (\SplFileInfo $file): array {
                preg_match_all("/can\('([A-Za-z]+:[A-Za-z]+)'\)/", File::get($file->getPathname()), $matches);

                return $matches[1];
            });
        $expected = $checkedByPolicies->merge(array_keys(config('filament-shield.custom_permissions')))->unique()->sort()->values()->all();

        $this->seed(PermissionSeeder::class);

        $this->assertSame($expected, Permission::orderBy('name')->pluck('name')->sort()->values()->all());
        $this->assertContains('View:Horizon', $expected);
        $this->assertContains('receive_reminder', $expected);
    }

    public function test_super_admin_role_gets_every_permission(): void
    {
        $this->seed(PermissionSeeder::class);

        $superAdmin = Role::findByName(config('filament-shield.super_admin.name'), 'web');

        $this->assertSame(Permission::count(), $superAdmin->permissions()->count());
    }

    public function test_seeder_is_idempotent_and_leaves_other_roles_alone(): void
    {
        $legal = Role::findOrCreate('legal', 'web');
        $legal->givePermissionTo(Permission::findOrCreate('View:Company', 'web'));

        $this->seed(PermissionSeeder::class);
        $permissionCount = Permission::count();
        $this->seed(PermissionSeeder::class);

        $this->assertSame($permissionCount, Permission::count());
        $this->assertSame(count(PermissionSeeder::permissionNames()), $permissionCount);
        $this->assertSame(['View:Company'], $legal->fresh()->permissions->pluck('name')->all());
    }

    public function test_database_seeder_seeds_base_data_without_accounts_outside_local(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(PermissionSeeder::permissionNames()), Permission::count());
        $this->assertSame(3, DocumentType::count());
        $this->assertNotNull(ReminderTemplate::globalSetting());
        $this->assertSame(0, User::count());
    }

    public function test_database_seeder_adds_dummy_accounts_in_local(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->seed(DatabaseSeeder::class);

        $superAdmin = User::where('email', 'super-admin@'.DummyAccountSeeder::EMAIL_DOMAIN)->sole();
        $this->assertTrue($superAdmin->hasRole('super_admin'));
        $this->assertTrue($superAdmin->can('ViewAny:Document'));
        $this->assertSame(Role::count(), User::count());
    }
}
