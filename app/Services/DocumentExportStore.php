<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Tempat file ekspor Excel yang dibuat di antrean (disk local, privat): satu folder per user dan per ekspor,
 * jadi file hanya bisa diunduh pemiliknya. File dihapus pembersihan harian setelah KEEP_HOURS.
 */
class DocumentExportStore
{
    public const DISK = 'local';

    public const DIRECTORY = 'exports';

    public const KEEP_HOURS = 24;

    public function directoryFor(int $userId, string $exportId): string
    {
        return self::DIRECTORY.'/'.$userId.'/'.$exportId;
    }

    /** Path relatif file ekspor milik user ini, atau null bila tidak ada / sudah dibersihkan. */
    public function find(int $userId, string $exportId): ?string
    {
        if (! preg_match('/^[0-9a-f-]{36}$/', $exportId)) {
            return null;
        }

        return collect(Storage::disk(self::DISK)->files($this->directoryFor($userId, $exportId)))
            ->first(fn (string $path): bool => str_ends_with($path, '.xlsx'));
    }

    /** @return int Jumlah folder ekspor yang dihapus. */
    public function cleanup(): int
    {
        $root = Storage::disk(self::DISK)->path(self::DIRECTORY);
        if (! is_dir($root)) {
            return 0;
        }
        $cutoff = now()->subHours(self::KEEP_HOURS)->getTimestamp();
        $deleted = 0;

        foreach (File::directories($root) as $userDirectory) {
            foreach (File::directories($userDirectory) as $exportDirectory) {
                if (File::lastModified($exportDirectory) < $cutoff) {
                    File::deleteDirectory($exportDirectory);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
