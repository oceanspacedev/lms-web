<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DocumentMasterPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['ReminderTemplate'] as $subject) {
            foreach (['ViewAny', 'View', 'Create', 'Update'] as $action) {
                $permission = Permission::findOrCreate($action.':'.$subject, 'web');
                Role::where('name', config('filament-shield.super_admin.name'))->where('guard_name', 'web')->get()
                    ->each(fn (Role $role) => $role->givePermissionTo($permission));
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
