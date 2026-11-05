<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Aksi lengkap Filament Shield untuk User dan Pengajuan Dokumen, sama dengan PermissionSeeder::RESOURCE_ACTIONS.
     * Ditulis tetap di sini supaya migrasi tidak ikut berubah bila seeder berubah.
     *
     * @var list<string>
     */
    private const ACTIONS = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
        'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder',
    ];

    private const SUBJECTS = ['User', 'DocumentRequest'];

    /**
     * Instalasi lama belum punya izin hapus User dan Pengajuan Dokumen sampai PermissionSeeder dijalankan ulang.
     * Izin dibuat bila belum ada lalu diberikan ke role super admin bila role itu ada. Role lain tidak diubah. Aman dijalankan ulang.
     */
    public function up(): void
    {
        $permissions = collect(self::SUBJECTS)
            ->flatMap(fn (string $subject): array => array_map(fn (string $action): string => "{$action}:{$subject}", self::ACTIONS))
            ->map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'));

        Role::query()->where('name', config('filament-shield.super_admin.name'))->where('guard_name', 'web')->first()?->givePermissionTo($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Izin dibiarkan; mencabutnya bisa mengambil hak yang sudah diatur lewat menu Role. */
    public function down(): void {}
};
