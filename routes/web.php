<?php

use App\Http\Controllers\DocumentDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/documents/{document}/download', DocumentDownloadController::class)
    ->middleware('auth:web')->name('documents.download');
