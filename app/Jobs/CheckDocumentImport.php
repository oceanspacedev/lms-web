<?php

namespace App\Jobs;

use App\Models\DocumentImport;
use App\Services\DocumentImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Periksa Excel + ZIP impor di latar belakang (membongkar ZIP besar bisa memakan waktu lama).
 */
class CheckDocumentImport implements ShouldQueue
{
    use Queueable;

    /** Pemeriksaan tidak diulang otomatis; bila gagal, user mengunggah ulang. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $importId) {}

    public function handle(DocumentImporter $importer): void
    {
        $import = DocumentImport::find($this->importId);
        if ($import !== null) {
            $importer->runCheck($import);
        }
    }

    /** Worker mati/timeout di tengah pemeriksaan: tandai gagal agar halaman tidak menunggu selamanya. */
    public function failed(?\Throwable $exception): void
    {
        $import = DocumentImport::find($this->importId);
        if ($import?->status === 'checking') {
            $import->update(['status' => 'check_failed', 'notes' => ['check_errors' => ['excel' => ['Pemeriksaan terhenti sebelum selesai. Unggah ulang file; bila berulang, bagi ZIP menjadi beberapa bagian.']]]]);
        }
    }
}
