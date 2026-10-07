<?php

use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\DocumentRequestAttachmentController;
use App\Http\Controllers\PublicDocumentRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/documents/{document}/download', DocumentDownloadController::class)
    ->middleware('auth:web')->name('documents.download');

Route::get('/pengajuan', [PublicDocumentRequestController::class, 'create'])->name('requests.public.create');
Route::post('/pengajuan', [PublicDocumentRequestController::class, 'store'])->middleware('throttle:public-request-create')->name('requests.public.store');
Route::get('/pengajuan/{token}', [PublicDocumentRequestController::class, 'status'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:public-request-status')->name('requests.public.status');
Route::post('/pengajuan/{token}', [PublicDocumentRequestController::class, 'revise'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:public-request-revise')->name('requests.public.revise');

Route::get('/admin/document-requests/{documentRequest}/attachments/{key}', DocumentRequestAttachmentController::class)
    ->middleware('auth:web')->name('requests.attachment');
