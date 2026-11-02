<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Data dasar yang aman di semua environment, ditambah akun dummy per role khusus di local.
     * Event model tetap aktif karena model (mis. ReminderTemplate, DocumentType) melengkapi datanya lewat event.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            DocumentTypeSeeder::class,
            ReminderTemplateSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DummyAccountSeeder::class);
        }
    }
}
