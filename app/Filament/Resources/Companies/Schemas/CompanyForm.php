<?php

namespace App\Filament\Resources\Companies\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama Badan Usaha')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('legal_form')
                    ->label('Bentuk Badan Usaha')
                    ->placeholder('PT, CV, Yayasan, Koperasi, dan lainnya')
                    ->required()
                    ->maxLength(50),
                TextInput::make('npwp')
                    ->label('NPWP')
                    ->maxLength(30),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
                Textarea::make('address')
                    ->label('Alamat')
                    ->rows(3)
                    ->maxLength(5000)
                    ->columnSpanFull(),
            ]);
    }
}
