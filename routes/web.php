<?php

use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\DocumentRequestAttachmentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/documents/{document}/download', DocumentDownloadController::class)
    ->middleware('auth:web')->name('documents.download');

Route::get('/admin/document-requests/{documentRequest}/attachments/{key}', DocumentRequestAttachmentController::class)
    ->middleware('auth:web')->name('requests.attachment');
