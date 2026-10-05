<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Judul')->searchable()->sortable(),
                TextColumn::make('document_number')->label('Nomor Dokumen')->searchable()->toggleable(),
                TextColumn::make('company.name')->label('Perusahaan')->sortable(),
                TextColumn::make('documentType.name')->label('Jenis Dokumen')->sortable(),
                TextColumn::make('currentVersion.expiry_date')->label('Tanggal Berakhir')->date('d/m/Y')->placeholder('Tanpa Masa Berlaku')->sortable(),
                TextColumn::make('expiry_status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => Document::EXPIRY_STATUSES[$state])
                    ->color(fn (string $state): string => Document::EXPIRY_STATUS_COLORS[$state]),
                TextColumn::make('pic.name')->label('PIC'),
            ])
            ->filters([
                SelectFilter::make('company')->label('Perusahaan')->relationship('company', 'name')->searchable()->preload(),
                SelectFilter::make('document_type')->label('Jenis Dokumen')->relationship('documentType', 'name')->searchable()->preload(),
                SelectFilter::make('expiry_status')->label('Status')->options(Document::EXPIRY_STATUSES)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->withExpiryStatus($data['value']) : $query),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
                EditAction::make()->label('Ubah'),
                Action::make('download')->label('Unduh')->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Document $record): string => route('documents.download', $record))->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => Gate::allows('view', $record)),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada dokumen')
            ->emptyStateDescription('Tambahkan dokumen legal untuk mulai mengarsipkan.');
    }
}
