<?php

namespace App\Filament\Resources\Documents\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;

class DocumentVersionForm
{
    public static function components(bool $hasExpiry): array
    {
        return [
            FileUpload::make('file')->label('File Versi Baru')->disk('local')->visibility('private')
                ->storeFiles(false)->preventFilePathTampering()->required()
                ->acceptedFileTypes(config('lms.allowed_mime_types'))->maxSize(config('lms.max_upload_size_kb'))
                ->helperText('PDF, DOC, DOCX, JPG, atau PNG. Maksimal '.(config('lms.max_upload_size_kb') / 1024).' MB.'),
            DatePicker::make('issued_date')->label('Tanggal Terbit Baru')->native(false),
            DatePicker::make('expiry_date')->label('Tanggal Berakhir Baru')->native(false)
                ->disabled(! $hasExpiry)->required($hasExpiry)
                ->rules(fn (Get $get): array => filled($get('issued_date')) ? ['after_or_equal:'.$get('issued_date')] : []),
            Textarea::make('change_note')->label('Catatan Perubahan')->required()->maxLength(10000)
                ->helperText('Jelaskan perubahan pada versi baru ini.'),
        ];
    }
}
