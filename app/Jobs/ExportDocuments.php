<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\DocumentExportStore;
use App\Services\DocumentSpreadsheetExporter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Buat file Excel daftar dokumen yang besar di latar belakang, lalu kirim tautan unduhnya ke lonceng notifikasi.
 */
class ExportDocuments implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /** @param list<int> $documentIds Urutan sesuai tabel saat tombol ekspor diklik. */
    public function __construct(public int $userId, public array $documentIds, public string $baseName) {}

    public function handle(DocumentSpreadsheetExporter $exporter, DocumentExportStore $store): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $exportId = (string) Str::uuid();
        $directory = $store->directoryFor($user->id, $exportId);
        $filename = $this->baseName.'-'.now(config('lms.reminder_timezone'))->format('Ymd-His').'.xlsx';
        $disk = Storage::disk(DocumentExportStore::DISK);
        $disk->makeDirectory($directory);
        $exporter->writeInOrder($this->documentIds, $disk->path($directory.'/'.$filename));

        Notification::make()->title('File ekspor siap diunduh')
            ->body(count($this->documentIds).' dokumen · '.$filename.'. Tautan berlaku '.DocumentExportStore::KEEP_HOURS.' jam.')
            ->success()
            ->actions([Action::make('download')->label('Unduh')->button()->url(route('exports.download', ['export' => $exportId]))])
            ->sendToDatabase($user);
    }

    public function failed(?Throwable $exception): void
    {
        $user = User::find($this->userId);
        if ($user !== null) {
            Notification::make()->title('Ekspor gagal')->body('File Excel tidak bisa dibuat. Coba ekspor lagi; bila berulang, hubungi admin.')
                ->danger()->sendToDatabase($user);
        }
    }
}
