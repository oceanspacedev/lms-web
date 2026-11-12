<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Unduh draf, soft file bertanda tangan, dan scan dokumen fisik pengajuan (disk `local`) sebagai file unduhan, untuk staf (policy `view`) dan pengaju lewat tautan bertoken. */
class DocumentRequestFileController extends Controller
{
    public function __invoke(DocumentRequest $documentRequest, string $kind): StreamedResponse
    {
        Gate::authorize('view', $documentRequest);

        return $this->respond($documentRequest, $kind);
    }

    public function publicDownload(string $token, string $kind): StreamedResponse
    {
        abort_unless(strlen($token) === 64 && ctype_alnum($token), 404);
        $documentRequest = DocumentRequest::where('public_token', $token)->first();
        abort_unless($documentRequest, 404);

        return $this->respond($documentRequest, $kind);
    }

    private function respond(DocumentRequest $documentRequest, string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['draft', 'signed', 'scan'], true), 404);
        $path = $documentRequest->{"{$kind}_path"};
        abort_unless(is_string($path) && str_starts_with($path, 'lms/requests/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);
        $name = $documentRequest->{"{$kind}_name"};
        $filename = is_string($name) && $name !== '' ? $name : Str::slug($documentRequest->title).'-'.$kind.'.'.pathinfo($path, PATHINFO_EXTENSION);
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN'];

        // Selalu diunduh, termasuk PDF dan gambar: pengaju langsung mendapat filenya untuk dicetak, tanpa pratinjau di browser.
        return Storage::disk('local')->download($path, $filename, $headers);
    }
}
