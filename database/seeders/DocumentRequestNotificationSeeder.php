<?php

namespace Database\Seeders;

use App\Models\DocumentRequestNotification;
use Illuminate\Database\Seeder;

class DocumentRequestNotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DocumentRequestNotification::factory()->create();
    }
}
