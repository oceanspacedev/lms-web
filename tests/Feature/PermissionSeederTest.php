<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\Users\UserResource;
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
        // Pulihkan dan hapus permanen dikunci ke super admin di policy (tanpa cek izin), tetapi izinnya tetap dibuat supaya seragam dengan menu lain.
        $lockedToSuperAdmin = collect(['Company', 'Document', 'DocumentType', 'DocumentRequest', 'User'])
            ->flatMap(fn (string $subject): array => array_map(fn (string $action): string => "{$action}:{$subject}", ['Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny']));
        $uniformOnly = collect(['DocumentRequest', 'User'])
            ->flatMap(fn (string $subject): array => ["Replicate:{$subject}", "Reorder:{$subject}"]);
        $expected = $checkedByPolicies->merge($lockedToSuperAdmin)->merge($uniformOnly)->merge(array_keys(config('filament-shield.custom_permissions')))->unique()->sort()->values()->all();

        $this->seed(PermissionSeeder::class);

        $this->assertSame($expected, Permission::orderBy('name')->pluck('name')->sort()->values()->all());
        $this->assertContains('View:Horizon', $expected);
        $this->assertContains('receive_reminder', $expected);
    }

    public function test_delete_restore_and_force_delete_permissions_exist_for_users_and_requests(): void
    {
        $this->seed(PermissionSeeder::class);

        foreach (['User', 'DocumentRequest'] as $subject) {
            foreach (['Delete', 'DeleteAny', 'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny'] as $action) {
                $this->assertTrue(Permission::where('name', "{$action}:{$subject}")->exists(), "{$action}:{$subject}");
            }
        }
        $this->assertSame(
            ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'restore', 'restoreAny', 'forceDelete', 'forceDeleteAny'],
            config('filament-shield.resources.manage')[UserResource::class],
        );
        $this->assertSame(
            config('filament-shield.resources.manage')[UserResource::class],
            config('filament-shield.resources.manage')[DocumentRequestResource::class],
        );
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
