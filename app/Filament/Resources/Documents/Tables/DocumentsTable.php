<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->heading('Arsip Dokumen')
            ->headerActions([
                Action::make('tableView')->label('Tabel')->icon('heroicon-o-list-bullet')
                    ->color(fn (ListDocuments $livewire): string => $livewire->isGridView() ? 'gray' : 'primary')
                    ->extraAttributes(fn (ListDocuments $livewire): array => ['aria-pressed' => $livewire->isGridView() ? 'false' : 'true'])
                    ->action(fn (ListDocuments $livewire) => $livewire->setDocumentView('table')),
                Action::make('gridView')->label('Grid')->icon('heroicon-o-squares-2x2')
                    ->color(fn (ListDocuments $livewire): string => $livewire->isGridView() ? 'primary' : 'gray')
                    ->extraAttributes(fn (ListDocuments $livewire): array => ['aria-pressed' => $livewire->isGridView() ? 'true' : 'false'])
                    ->action(fn (ListDocuments $livewire) => $livewire->setDocumentView('grid')),
            ])
            ->content(fn (ListDocuments $livewire): ?View => $livewire->isGridView()
                ? view('filament.resources.documents.document-grid', [
                    'records' => $livewire->getTableRecords(),
                    'table' => $livewire->getTable(),
                ]) : null)
            ->searchPlaceholder('Cari judul, nomor, atau nama file')
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
                TextColumn::make('currentVersion.file_name')->label('Nama File')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company')->label('Perusahaan')->relationship('company', 'name')->searchable()->preload(),
                SelectFilter::make('document_type')->label('Jenis Dokumen')->relationship('documentType', 'name')->searchable()->preload(),
                SelectFilter::make('expiry_status')->label('Status')->options(Document::EXPIRY_STATUSES)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->withExpiryStatus($data['value']) : $query),
                SelectFilter::make('pic')->label('PIC')->relationship('pic', 'name')->searchable()->preload(),
                SelectFilter::make('file_format')->label('Format File')
                    ->options([
                        'application/pdf' => 'PDF',
                        'application/msword' => 'DOC',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
                        'image/jpeg' => 'JPG',
                        'image/png' => 'PNG',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('currentVersion', fn (Builder $version): Builder => $version->where('mime_type', $data['value']))
                        : $query),
            ], layout: FiltersLayout::Hidden)
            ->filtersFormColumns(1)
            ->deferFilters(false)
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
