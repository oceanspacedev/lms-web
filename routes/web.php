<?php

use App\Filament\Auth\Pages\EmailLogin;
use App\Filament\Auth\Pages\PhoneLogin;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\DocumentRequestAttachmentController;
use App\Http\Controllers\DocumentRequestFileController;
use App\Http\Controllers\PanduanVideoController;
use App\Http\Controllers\PublicDocumentRequestController;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Login tanpa password (WhatsApp dan email), ditawarkan di bawah form login panel.
Route::middleware([
    DisableBladeIconComponents::class,
    DispatchServingFilamentEvent::class,
])->group(function () {
    Route::get('/phone-login', PhoneLogin::class)->name('phone-login');
    Route::get('/email-login', EmailLogin::class)->name('email-login');

    // Magic link dari email. Membuka URL saja tidak menghabiskan token; lihat EmailLogin::$magicToken.
    Route::get('/email-login/link/{token}', EmailLogin::class)
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('email-login.link');
});

Route::get('/admin/documents/{document}/download', DocumentDownloadController::class)
    ->middleware('auth:web')->name('documents.download');

Route::get('/admin/panduan/video/{file}', PanduanVideoController::class)
    ->where('file', '[0-9]{2}-[a-z0-9-]+\.(mp4|jpg)')->middleware('auth:web')->name('panduan.video');

Route::get('/pengajuan', [PublicDocumentRequestController::class, 'create'])->name('requests.public.create');
Route::post('/pengajuan', [PublicDocumentRequestController::class, 'store'])->middleware('throttle:public-request-create')->name('requests.public.store');
Route::get('/pengajuan/cek', [PublicDocumentRequestController::class, 'lookup'])->name('requests.public.lookup');
Route::post('/pengajuan/cek', [PublicDocumentRequestController::class, 'lookupSubmit'])->middleware('throttle:public-request-lookup')->name('requests.public.lookup.submit');
Route::get('/pengajuan/{token}', [PublicDocumentRequestController::class, 'status'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:public-request-status')->name('requests.public.status');
Route::post('/pengajuan/{token}', [PublicDocumentRequestController::class, 'revise'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:public-request-revise')->name('requests.public.revise');

// Pengajuan di tempat sampah ikut ter-bind supaya super admin bisa mengunduh berkasnya; policy `view` menolak pengguna lain.
Route::get('/admin/document-requests/{documentRequest}/attachments/{key}', DocumentRequestAttachmentController::class)
    ->middleware('auth:web')->withTrashed()->name('requests.attachment');
Route::get('/admin/document-requests/{documentRequest}/files/{kind}', DocumentRequestFileController::class)
    ->whereIn('kind', ['draft', 'signed'])->middleware('auth:web')->withTrashed()->name('requests.file');
Route::get('/pengajuan/{token}/berkas/{kind}', [DocumentRequestFileController::class, 'publicDownload'])->where('token',
    '[A-Za-z0-9]{64}')->middleware('throttle:public-request-status')->name('requests.public.file');
Route::post('/pengajuan/{token}/scan', [PublicDocumentRequestController::class, 'uploadScan'])->where('token',
    '[A-Za-z0-9]{64}')->middleware('throttle:public-request-revise')->name('requests.public.scan');
Route::post('/pengajuan/{token}/alasan', [PublicDocumentRequestController::class, 'lateReason'])->where('token',
    '[A-Za-z0-9]{64}')->middleware('throttle:public-request-revise')->name('requests.public.reason');
