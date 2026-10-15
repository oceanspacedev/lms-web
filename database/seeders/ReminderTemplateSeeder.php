<?php

namespace Database\Seeders;

use App\Models\ReminderTemplate;
use Illuminate\Database\Seeder;

class ReminderTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            if (ReminderTemplate::globalSetting() === null) {
                ReminderTemplate::create([
                    'name' => 'Pengingat Semua Dokumen',
                    'is_active' => true,
                    'schedule_mode' => 'interval',
                    'start_before_days' => 30,
                    'interval_days' => 7,
                    'body' => ReminderTemplate::DEFAULT_BODY,
                ]);
            }
        }
    }
}
