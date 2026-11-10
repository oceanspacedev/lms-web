<?php

namespace App\Jobs;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\DocumentImport;
use App\Services\DocumentImporter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Impor satu tahap (CHUNK_SIZE baris) di latar belakang, lalu antrekan tahap berikutnya sampai semua baris selesai.
 * Tahap pendek membuat kemajuan tersimpan rutin; bila worker berhenti, user cukup klik "Lanjutkan impor".
 */
class ProcessDocumentImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Satu tahap mengunggah hingga CHUNK_SIZE file ke penyimpanan. */
    public int $timeout = 600;

    public function __construct(public int $importId) {}

    public function handle(DocumentImporter $importer): void
    {
        $import = DocumentImport::find($this->importId);
        if ($import === null || $import->status !== 'importing' || $import->user === null) {
            return;
        }

        $processedBefore = $import->processedCount();
        $import = $importer->processChunk($import, $import->user);

        if ($import->status === 'completed') {
            Notification::make()->title('Impor selesai')
                ->body("{$import->imported_count} berhasil, {$import->skipped_count} dilewati, {$import->failed_count} gagal.")
                ->success()
                ->actions([Action::make('open')->label('Lihat laporan')->button()->url(DocumentResource::getUrl('import', ['batch' => $import->id], panel: 'admin'))])
                ->sendToDatabase($import->user);

            return;
        }

        // Tahap berikutnya hanya bila tahap ini membuat kemajuan (bila tidak, batch sedang diproses job lain).
        if ($import->status === 'importing' && $import->countRows('ready') > 0 && $import->processedCount() > $processedBefore) {
            self::dispatch($import->id);
        }
    }
}
