<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class DocumentDownloadController extends Controller
{
    /**
     * File di S3 diunduh lewat signed URL; file di disk lokal dikirim langsung setelah otorisasi.
     */
    public function __invoke(Request $request, Document $document): Response
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
        $disk = $version->storage();
        abort_unless($disk->exists($version->file_path), 404);

        $inline = $request->boolean('preview') && in_array($version->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true);
        $filename = str_replace(["\r", "\n", '/', '\\'], '_', $version->stored_name);
        $fallback = str_replace('%', '_', Str::ascii($filename));
        $disposition = HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $filename, $fallback);

        if (config("filesystems.disks.{$version->disk}.driver") === 's3') {
            $url = $disk->temporaryUrl($version->file_path, now()->addMinutes(config('lms.signed_url_minutes')), [
                'ResponseContentDisposition' => $disposition,
                'ResponseContentType' => $version->mime_type,
            ]);
            $response = redirect()->away($url)->withHeaders(['Cache-Control' => 'no-store, private']);
        } else {
            $response = $disk->response($version->file_path, $fallback, [
                'Content-Type' => $version->mime_type,
                'Content-Disposition' => $disposition,
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $document->activities()->create([
            'document_version_id' => $version->id,
            'user_id' => $request->user()->id,
            'event' => $request->boolean('preview') ? 'previewed' : 'downloaded',
        ]);

        return $response;
    }
}
