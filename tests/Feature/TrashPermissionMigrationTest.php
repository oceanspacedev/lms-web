<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Instalasi lama mendapat izin hapus, pulihkan, dan hapus permanen User dan Pengajuan tanpa menjalankan seeder. */
class TrashPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_05_073713_grant_trash_permissions_to_super_admin.php');
    }

    /** @return list<string> */
    private function expectedNames(): array
    {
        return collect(['User', 'DocumentRequest'])
            ->flatMap(fn (string $subject): array => array_map(fn (string $action): string => "{$action}:{$subject}", PermissionSeeder::RESOURCE_ACTIONS[$subject]))
            ->all();
    }

    /** Migrasi ini sudah berjalan saat RefreshDatabase; izinnya dihapus lagi untuk meniru instalasi lama. */
    private function forgetPermissions(): void
    {
        Permission::whereIn('name', $this->expectedNames())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_creates_user_and_request_permissions_and_gives_them_to_super_admin_only(): void
    {
        $this->forgetPermissions();
        $superAdmin = Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        $staff = Role::findOrCreate('staff_legal', 'web');
        $kept = Permission::findOrCreate('ViewAny:Document', 'web');
        $staff->givePermissionTo($kept);
        $superAdmin->givePermissionTo($kept);
        $this->assertFalse(Permission::where('name', 'Delete:User')->exists());

        $this->migration()->up();
        $this->migration()->up();

        $this->assertContains('Delete:User', $this->expectedNames());
        $this->assertContains('DeleteAny:DocumentRequest', $this->expectedNames());
        $this->assertSame([], array_diff($this->expectedNames(), PermissionSeeder::permissionNames()));
        foreach ($this->expectedNames() as $name) {
            $this->assertSame(1, Permission::where('name', $name)->where('guard_name', 'web')->count(), $name);
        }
        $superAdmin = $superAdmin->fresh();
        $this->assertEqualsCanonicalizing([...$this->expectedNames(), 'ViewAny:Document'], $superAdmin->permissions->pluck('name')->all());
        $this->assertSame(['ViewAny:Document'], $staff->fresh()->permissions->pluck('name')->all());
    }

    public function test_works_without_super_admin_role_and_before_soft_delete_columns_exist(): void
    {
        $this->forgetPermissions();
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('deleted_at'));

        $this->migration()->up();

        $this->assertFalse(Role::where('name', config('filament-shield.super_admin.name'))->exists());
        $this->assertSame(count($this->expectedNames()), Permission::whereIn('name', $this->expectedNames())->count());
    }
}
