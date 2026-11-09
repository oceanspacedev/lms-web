<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Ukuran file dalam format Indonesia: koma desimal dan tanpa ",0" yang tidak perlu (108 B, 1,5 MB).
 */
class FileSize
{
    public static function format(int $bytes): string
    {
        return Number::withLocale('id', fn (): string => Number::fileSize($bytes, maxPrecision: 1));
    }
}
