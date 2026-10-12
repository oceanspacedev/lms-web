<?php

namespace App\Services;

use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;

/**
 * Nama file penyimpanan yang mudah dilacak saat insiden, contoh
 * `pt-abc_perjanjian-kerja-sama_001-pks-2026_sewa-gudang_dok12-v2_20261012-091500.pdf`.
 */
class StorageFileName
{
    public const SEGMENT_LIMIT = 50;

    /**
     * Gabungkan bagian yang terisi dengan `_`, lalu tambahkan waktu upload (WIB) dan ekstensi.
     *
     * @param  array<int, string|null>  $parts
     */
    public static function build(array $parts, CarbonInterface $uploadedAt, string $extension): string
    {
        $segments = collect($parts)
            ->map(fn (?string $part): string => self::segment((string) $part))
            ->filter()
            ->push($uploadedAt->copy()->setTimezone(config('lms.reminder_timezone'))->format('Ymd-His'));

        return $segments->implode('_').'.'.strtolower($extension);
    }

    public static function segment(string $value, int $limit = self::SEGMENT_LIMIT): string
    {
        return rtrim(Str::limit(Str::slug(preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? ''), $limit, ''), '-');
    }

    public static function folder(?string $name): string
    {
        return self::segment((string) $name) ?: 'lainnya';
    }

    /**
     * Nama badan usaha beserta bentuk usahanya, misalnya "PT Abc" dari bentuk "PT" dan nama "Abc".
     */
    public static function companyName(?Company $company): ?string
    {
        if (! $company) {
            return null;
        }

        $form = trim((string) $company->legal_form);

        return $form === '' || Str::startsWith(Str::lower($company->name), Str::lower($form).' ') ? $company->name : "{$form} {$company->name}";
    }

    /**
     * Tambahkan akhiran -2, -3, dan seterusnya bila nama sudah dipakai di disk.
     */
    public static function available(FilesystemAdapter $disk, string $directory, string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $candidate = $filename;

        for ($suffix = 2; $disk->exists("{$directory}/{$candidate}"); $suffix++) {
            $candidate = "{$name}-{$suffix}.{$extension}";
        }

        return $candidate;
    }
}
