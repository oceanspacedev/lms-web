<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentRequestAttachmentController extends Controller
{
    public function __invoke(DocumentRequest $documentRequest, string $key): StreamedResponse
    {
        Gate::authorize('view', $documentRequest);
        $path = $documentRequest->attachments[$key] ?? null;
        abort_unless(is_string($path) && str_starts_with($path, 'lms/requests/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);
        $item = collect($documentRequest->requirements['attachments'] ?? [])->firstWhere('key', $key);
        abort_unless($item, 404);
        $filename = Str::slug($item['label']).'.'.pathinfo($path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($path, $filename, ['Cache-Control' => 'no-store, private']);
    }
}
