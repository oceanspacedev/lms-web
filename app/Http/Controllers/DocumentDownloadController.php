<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class DocumentDownloadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('view', $document);
        $versionId = $request->query('version');
        if ($versionId !== null) {
            abort_unless(is_string($versionId) && ctype_digit($versionId), 404);
            $version = $document->versions()->findOrFail($versionId);
        } else {
            $version = $document->currentVersion;
            abort_unless($version && $version->document_id === $document->id && $version->is_current, 404);
        }
        abort_unless(Storage::disk('s3')->exists($version->file_path), 404);

        $inline = $request->boolean('preview') && in_array($version->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true);
        $filename = str_replace(["\r", "\n", '/', '\\'], '_', $version->file_name);
        $fallback = str_replace('%', '_', Str::ascii($filename));
        $url = Storage::disk('s3')->temporaryUrl($version->file_path, now()->addMinutes(config('lms.signed_url_minutes')), [
            'ResponseContentDisposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $filename, $fallback),
            'ResponseContentType' => $version->mime_type,
        ]);

        $document->activities()->create([
            'document_version_id' => $version->id,
            'user_id' => $request->user()->id,
            'event' => $request->boolean('preview') ? 'previewed' : 'downloaded',
        ]);

        return redirect()->away($url)->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
