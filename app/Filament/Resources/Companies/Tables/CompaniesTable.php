<?php

namespace App\Filament\Resources\Companies\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama Perusahaan')->searchable()->sortable(),
                TextColumn::make('legal_form')->label('Bentuk Badan Usaha')->searchable()->sortable(),
                TextColumn::make('npwp')->label('NPWP')->searchable()->placeholder('Belum diisi'),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('updated_at')->label('Terakhir Diperbarui')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Status')->placeholder('Semua status')->trueLabel('Aktif')->falseLabel('Nonaktif'),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Belum ada perusahaan')
            ->emptyStateDescription('Tambahkan perusahaan atau badan usaha sebagai referensi dokumen.');
    }
}
