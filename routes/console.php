<?php

use App\Models\EmailOtp;
use App\Models\WhatsappOtp;
use App\Services\DocumentExportStore;
use App\Services\DocumentImporter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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
    ->withoutOverlapping(30)
    ->onFailure(fn () => Log::error('Pengingat dokumen terjadwal gagal sebagian atau seluruhnya; lihat log di atas.'));

Schedule::command('lms:send-request-notifications')->everyMinute()->withoutOverlapping(5)
    ->onFailure(fn () => Log::warning('Notifikasi pengajuan gagal terkirim; akan dicoba kembali.'));

Schedule::command('lms:send-request-reminders')
    ->dailyAt(config('lms.reminder_time'))
    ->timezone(config('lms.reminder_timezone'))
    ->withoutOverlapping(30);

Schedule::command('model:prune', ['--model' => [WhatsappOtp::class, EmailOtp::class]])->daily();

Schedule::command('lms:clean-import-files')->daily();

Artisan::command('lms:clean-export-files', function (DocumentExportStore $store) {
    $this->info('File ekspor lama yang dihapus: '.$store->cleanup());
})->purpose('Hapus file ekspor Excel dari antrean yang lebih dari 24 jam');

Schedule::command('lms:clean-export-files')->hourly();
