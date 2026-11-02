<?php

namespace Database\Seeders;

use App\Models\ReminderTemplate;
use Illuminate\Database\Seeder;

/**
 * Pengaturan pengingat global untuk pengembangan. Di production pengaturan dibuat admin lewat halaman Pengaturan WhatsApp.
 */
class ReminderTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ReminderTemplate::globalSetting() !== null) {
            return;
        }

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
