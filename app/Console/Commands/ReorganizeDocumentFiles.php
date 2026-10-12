<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('lms:reorganize-files {--dry-run : Tampilkan rencana pemindahan tanpa mengubah file atau database}')]
#[Description('Pindahkan file dokumen ke struktur folder per jenis dokumen')]
class ReorganizeDocumentFiles extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['moved' => 0, 'unchanged' => 0, 'failed' => 0];

        DocumentVersion::query()->orderBy('id')->each(function (DocumentVersion $version) use ($dryRun, &$counts): void {
            $disk = $version->storage();
            $document = Document::withTrashed()->find($version->document_id);
            $source = $version->file_path;
            $target = $document->storagePath($version->version_number, pathinfo($source, PATHINFO_EXTENSION));

            if ($source === $target) {
                $counts['unchanged']++;

                return;
            }

            if ($dryRun) {
                $this->line("{$source} → {$target}");
                $counts['moved']++;

                return;
            }

            try {
                $sourceExists = $disk->exists($source);
                $targetExists = $disk->exists($target);

                if ($targetExists && $sourceExists) {
                    throw new \RuntimeException('tujuan sudah berisi file lain');
                }

                if (! $targetExists) {
                    if (! $sourceExists) {
                        throw new \RuntimeException('file asal tidak ditemukan');
                    }

                    if (! $disk->copy($source, $target)) {
                        throw new \RuntimeException('penyalinan gagal');
                    }
                }

                DB::table('document_versions')->where('id', $version->id)->update(['file_path' => $target]);

                if ($sourceExists && ! $disk->delete($source)) {
                    $this->warn("File lama belum terhapus: {$source}");
                }

                $this->line("{$source} → {$target}");
                $counts['moved']++;
            } catch (Throwable $exception) {
                $this->error("Gagal memindahkan versi #{$version->id} ({$source}): {$exception->getMessage()}");
                $counts['failed']++;
            }
        });

        $this->info(($dryRun ? 'Akan dipindahkan' : 'Dipindahkan').": {$counts['moved']}, sudah rapi: {$counts['unchanged']}, gagal: {$counts['failed']}.");

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
