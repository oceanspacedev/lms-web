<?php

namespace App\Http\Controllers;

use App\Services\DocumentExportStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh file ekspor Excel yang dibuat di antrean. Hanya pemilik ekspor yang bisa mengunduh, karena file dicari di folder user yang login.
 */
class ExportDownloadController extends Controller
{
    public function __invoke(string $export, DocumentExportStore $store): StreamedResponse
    {
        $path = $store->find((int) auth()->id(), $export);
        abort_if($path === null, 404);

        return Storage::disk(DocumentExportStore::DISK)->download($path, basename($path), ['Cache-Control' => 'no-store, private']);
    }
}
