<?php

namespace App\Support;

use Aws\Exception\AwsException;
use League\Flysystem\FilesystemException;
use Throwable;

/**
 * Mengenali error karena penyimpanan file (S3) tidak bisa dihubungi atau menolak permintaan,
 * agar pengguna mendapat pesan yang jelas, bukan "gangguan sistem" umum.
 */
class StorageFailure
{
    public const MESSAGE = 'Penyimpanan file sedang tidak dapat dihubungi. Tidak ada yang berubah; coba lagi beberapa saat lagi. Bila berulang, hubungi admin.';

    /**
     * Benar bila error ini atau penyebabnya (rantai `getPrevious()`) berasal dari penyimpanan file.
     */
    public static function caused(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof FilesystemException || $current instanceof AwsException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Isi pesan untuk pengguna: khusus penyimpanan bila penyebabnya S3, selain itu `$fallback`.
     */
    public static function message(Throwable $exception, string $fallback): string
    {
        return self::caused($exception) ? self::MESSAGE : $fallback;
    }
}
