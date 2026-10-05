<?php

namespace App\Filament\Resources\DocumentTypes\Schemas;

use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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
                TagsInput::make('reminder_days')
                    ->label('Hari Pengingat Sebelum Berakhir')
                    ->placeholder('Ketik jumlah hari, lalu tekan Enter')
                    ->helperText('Contoh: 90, 60, 30. Masukkan angka bulat positif tanpa duplikat. Dikosongkan jika tidak perlu pengingat.')
                    ->splitKeys([','])
                    ->tagSuffix(' hari')
                    ->rules(['array'])
                    ->nestedRecursiveRules(['integer', 'min:1', 'distinct'])
                    ->disabled(fn (Get $get): bool => ! $get('has_expiry')),
            ]);
    }
}
