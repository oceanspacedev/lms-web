<?php

namespace App\Filament\Resources\DocumentTypes\Tables;

use App\Models\DocumentType;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DocumentTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Jenis Dokumen')->searchable()->sortable(),
                TextColumn::make('has_expiry')
                    ->label('Masa Berlaku')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Memiliki Masa Berlaku' : 'Tanpa Masa Berlaku')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('reminders')
                    ->label('Pengingat')
                    ->state(fn (DocumentType $record): string => $record->has_expiry && $record->reminder_days !== []
                        ? implode(', ', $record->reminder_days).' hari sebelum berakhir'
                        : 'Tanpa pengingat'),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Status')->placeholder('Semua status')->trueLabel('Aktif')->falseLabel('Nonaktif'),
                TernaryFilter::make('has_expiry')->label('Masa Berlaku')->placeholder('Semua jenis')->trueLabel('Memiliki Masa Berlaku')->falseLabel('Tanpa Masa Berlaku'),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Belum ada jenis dokumen')
            ->emptyStateDescription('Tambahkan jenis dokumen dan atur hari pengingatnya.');
    }
}
