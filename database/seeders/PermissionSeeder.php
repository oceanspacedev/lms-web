<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Semua permission aplikasi, sama persis dengan yang dicek policy di app/Policies ditambah custom permission
 * di config/filament-shield.php. Role super admin mendapat semuanya. Aman dijalankan ulang di production.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Aksi lengkap bawaan Filament Shield.
     *
     * @var list<string>
     */
    public const ALL_ACTIONS = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
        'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder',
    ];

    /**
     * Aksi per subjek. Subjek tanpa hapus/pulihkan hanya mendapat aksi yang benar-benar dipakai policy-nya.
     *
     * @var array<string, list<string>>
     */
    public const RESOURCE_ACTIONS = [
        'Company' => self::ALL_ACTIONS,
        'Document' => self::ALL_ACTIONS,
        'DocumentType' => self::ALL_ACTIONS,
        'Role' => self::ALL_ACTIONS,
        'DocumentRequest' => self::ALL_ACTIONS,
        'ReminderTemplate' => ['ViewAny', 'View', 'Create', 'Update'],
        'ReminderLog' => ['ViewAny', 'View'],
        'User' => self::ALL_ACTIONS,
    ];

    public function run(): void
    {
        $permissions = collect(self::permissionNames())
            ->map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'));

        Role::findOrCreate(config('filament-shield.super_admin.name'), 'web')->givePermissionTo($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    public static function permissionNames(): array
    {
        return collect(self::RESOURCE_ACTIONS)
            ->flatMap(fn (array $actions, string $subject): array => array_map(fn (string $action): string => "{$action}:{$subject}", $actions))
            ->merge(array_keys(config('filament-shield.custom_permissions', [])))
            ->values()
            ->all();
    }
}
