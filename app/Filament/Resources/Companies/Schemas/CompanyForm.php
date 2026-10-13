<?php

namespace App\Filament\Resources\Companies\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

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
                    ->mask(RawJs::make(<<<'JS'
                        $input.replace(/\D/g, '').length > 15 ? '9999999999999999' : '99.999.999.9-999.999'
                        JS))
                    ->placeholder('99.999.999.9-999.999')
                    ->extraInputAttributes(['inputmode' => 'numeric'])
                    ->regex('/^(\d{2}\.\d{3}\.\d{3}\.\d-\d{3}\.\d{3}|\d{15}|\d{16})$/')
                    ->validationMessages(['regex' => 'NPWP harus 15 digit (99.999.999.9-999.999) atau 16 digit angka.'])
                    ->helperText('15 digit lama atau 16 digit (NIK/NPWP baru).')
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
