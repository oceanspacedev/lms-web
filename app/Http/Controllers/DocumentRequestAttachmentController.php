<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentRequestAttachmentController extends Controller
{
    public function __invoke(Request $request, DocumentRequest $documentRequest, string $key): StreamedResponse
    {
        Gate::authorize('view', $documentRequest);
        $path = $documentRequest->attachments[$key] ?? null;
        abort_unless(is_string($path) && str_starts_with($path, 'lms/requests/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);
        $item = collect($documentRequest->requirements['attachments'] ?? [])->firstWhere('key', $key);
        abort_unless($item, 404);
        $filename = Str::slug($item['label']).'.'.pathinfo($path, PATHINFO_EXTENSION);

        if ($request->boolean('preview')) {
            $mime = Storage::disk('local')->mimeType($path);
            abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true), 415, 'Preview tersedia untuk PDF dan gambar.');

            return Storage::disk('local')->response($path, $filename, [
                'Content-Type' => $mime, 'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN',
            ]);
        }

        return Storage::disk('local')->download($path, $filename, ['Cache-Control' => 'no-store, private']);
    }
}
