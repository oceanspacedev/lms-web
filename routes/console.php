<?php

use App\Models\LoginCode;
use App\Services\DocumentImporter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('lms:clean-import-files', function (DocumentImporter $importer) {
    $this->info('Folder impor sementara yang dihapus: '.$importer->cleanupStaleFiles());
})->purpose('Hapus file sementara impor dokumen lama yang lebih dari 24 jam');

Schedule::command('lms:send-reminders')
    ->dailyAt(config('lms.reminder_time'))
    ->timezone(config('lms.reminder_timezone'))
    ->withoutOverlapping(30);

Schedule::command('lms:send-request-notifications')->everyMinute()->withoutOverlapping(5);

Schedule::command('lms:send-request-reminders')
    ->dailyAt(config('lms.reminder_time'))
    ->timezone(config('lms.reminder_timezone'))
    ->withoutOverlapping(30);

Schedule::command('model:prune', ['--model' => [LoginCode::class]])->daily();

Schedule::command('lms:clean-import-files')->daily();
