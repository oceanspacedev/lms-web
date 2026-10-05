<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Models\DocumentType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')
                    ->label('Perusahaan')
                    ->relationship('company', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true))
                    ->searchable()->preload()->required()->disabledOn('edit')->validatedWhenNotDehydrated(false)
                    ->rules([Rule::exists('companies', 'id')->where('is_active', true)]),
                Select::make('document_type_id')
                    ->label('Jenis Dokumen')
                    ->relationship('documentType', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true))
                    ->searchable()->preload()->required()->live()->disabledOn('edit')->validatedWhenNotDehydrated(false)
                    ->rules([Rule::exists('document_types', 'id')->where('is_active', true)]),
                TextInput::make('title')->label('Judul')->required()->maxLength(255),
                TextInput::make('document_number')->label('Nomor Dokumen')->maxLength(255),
                TextInput::make('counterparty')->label('Pihak Lawan')->maxLength(255),
                Select::make('pic_user_id')->label('PIC')->relationship('pic', 'name')->searchable()->preload()->required(),
                DatePicker::make('issued_date')->label('Tanggal Terbit')->native(false)->visibleOn('create'),
                DatePicker::make('expiry_date')
                    ->label('Tanggal Berakhir')->native(false)->visibleOn('create')
                    ->disabled(fn (Get $get): bool => ! DocumentType::find($get('document_type_id'))?->has_expiry)
                    ->required(fn (Get $get): bool => (bool) DocumentType::find($get('document_type_id'))?->has_expiry)
                    ->rules(fn (Get $get): array => filled($get('issued_date')) ? ['after_or_equal:data.issued_date'] : []),
                Textarea::make('notes')->label('Catatan')->maxLength(10000)->columnSpanFull(),
                FileUpload::make('file')
                    ->label('File Dokumen')->disk('local')->visibility('private')->storeFiles(false)
                    ->preventFilePathTampering()
                    ->acceptedFileTypes(config('lms.allowed_mime_types'))
                    ->maxSize(config('lms.max_upload_size_kb'))
                    ->required()->visibleOn('create')->columnSpanFull()
                    ->helperText('PDF, DOC, DOCX, JPG, atau PNG. Maksimal '.(config('lms.max_upload_size_kb') / 1024).' MB.'),
            ]);
    }
}
