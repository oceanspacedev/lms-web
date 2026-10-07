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
        return $table->striped()->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('name')->label('Badan Usaha')->searchable()->sortable()->weight('medium')->limit(40)->wrap(),
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
                EditAction::make()->label('Ubah')->iconButton()->tooltip('Ubah'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Belum ada badan usaha')
            ->emptyStateDescription(null);
    }
}
