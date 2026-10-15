<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lms:check-production')]
#[Description('Periksa konfigurasi produksi tanpa mengubah data atau menghubungi layanan eksternal')]
class CheckProductionReadiness extends Command
{
    public function handle(): int
    {
        $disk = (string) config('filesystems.default');
        $usesS3 = config("filesystems.disks.{$disk}.driver") === 's3';
        $checks = [
            'APP_ENV production' => config('app.env') === 'production',
            'APP_DEBUG nonaktif' => config('app.debug') === false,
            'APP_KEY tersedia' => filled(config('app.key')),
            'APP_URL HTTPS' => parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https',
            'Cookie sesi secure' => config('session.secure') === true,
            'Cookie sesi HttpOnly' => config('session.http_only') === true,
            'Sesi persisten' => ! in_array(config('session.driver'), ['array', 'null'], true),
            'Cache persisten untuk lock scheduler' => ! in_array(config('cache.default'), ['array', 'null'], true),
            'Debugbar nonaktif' => ! (config('debugbar.enabled') ?? config('app.debug')),
            "Disk dokumen ({$disk}) privat" => in_array(config("filesystems.disks.{$disk}.driver"), ['local', 's3'], true) && config("filesystems.disks.{$disk}.visibility", 'private') === 'private',
            'Kredensial S3 dan bucket tersedia' => ! $usesS3 || filled(config('filesystems.disks.s3.key')) && filled(config('filesystems.disks.s3.secret')) && filled(config('filesystems.disks.s3.bucket')),
            'Endpoint S3 HTTPS' => ! $usesS3 || blank(config('filesystems.disks.s3.endpoint')) || parse_url((string) config('filesystems.disks.s3.endpoint'), PHP_URL_SCHEME) === 'https',
            'Sertifikat S3 diverifikasi' => ! $usesS3 || config('filesystems.disks.s3.http.verify') === true,
            'WagHub HTTPS dan token tersedia' => parse_url((string) config('services.waghub.url'), PHP_URL_SCHEME) === 'https' && filled(config('services.waghub.token')),
            'Purpose WagHub tersedia' => filled(config('services.waghub.purpose')),
            'Purpose OTP WagHub tersedia' => filled(config('services.waghub.otp_purpose')),
            'Scheduler Asia/Jakarta' => config('lms.reminder_timezone') === 'Asia/Jakarta',
            'Batas retry positif' => (int) config('lms.reminder_max_attempts') > 0,
            'Direktori runtime writable' => is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')),
            'Config cache aktif' => app()->configurationIsCached(),
        ];
        $this->table(['Pemeriksaan', 'Hasil'], collect($checks)->map(fn (bool $passed, string $label): array => [$label, $passed ? 'OK' : 'PERLU DIATUR'])->values()->all());
        $this->line('Pemeriksaan ini tidak membuktikan koneksi S3/WagHub, pengiriman WhatsApp, cron aktif, backup terjadwal, atau restore pada server produksi.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
