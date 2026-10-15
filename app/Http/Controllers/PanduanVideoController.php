<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PanduanVideoController extends Controller
{
    public const DIRECTORY = 'videos/panduan';

    /**
     * Video dan poster halaman Panduan, hanya untuk user yang sudah masuk.
     * Dikirim lewat aplikasi (bukan folder public) agar permintaan Range (206) selalu didukung,
     * termasuk di `php artisan serve`; tanpa Range, klik langkah tidak bisa melompat ke detik tertentu.
     */
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = resource_path(self::DIRECTORY.'/'.$file);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => str_ends_with($file, '.mp4') ? 'video/mp4' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
