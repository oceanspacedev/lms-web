<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['ViewAny:User', 'View:User', 'Create:User', 'Update:User'] as $permissionName) {
            $permission = Permission::findOrCreate($permissionName, 'web');
            Role::query()->where('name', config('filament-shield.super_admin.name'))->where('guard_name', 'web')
                ->get()->each(fn (Role $role) => $role->givePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
