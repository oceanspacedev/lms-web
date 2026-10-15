<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class ReminderLogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['ViewAny:ReminderLog', 'View:ReminderLog', 'receive_reminder'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }
}
