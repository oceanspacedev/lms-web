<?php

namespace App\Filament\Resources\DocumentTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DocumentTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama Jenis Dokumen')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->helperText('Nonaktifkan jenis dokumen yang tidak digunakan lagi. Data tetap tersimpan.'),
                Toggle::make('has_expiry')
                    ->label('Memiliki Masa Berlaku')
                    ->default(false)
                    ->live(),
            ]);
    }
}
