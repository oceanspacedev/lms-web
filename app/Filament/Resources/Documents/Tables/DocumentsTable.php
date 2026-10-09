<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Models\Document;
use App\Services\DocumentSpreadsheetExporter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
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
            ->striped()->paginated([10, 25, 50])
            ->heading('Arsip Dokumen')
            ->headerActions([
                Action::make('exportExcel')->label('Ekspor Excel')->icon('heroicon-o-table-cells')->color('gray')
                    ->tooltip('Unduh daftar dokumen sesuai pencarian dan filter yang aktif')
                    ->visible(fn (): bool => Gate::allows('viewAny', Document::class))
                    ->action(function (ListDocuments $livewire) {
                        Gate::authorize('viewAny', Document::class);

                        return app(DocumentSpreadsheetExporter::class)->download($livewire->getTableQueryForExport(), 'dokumen');
                    }),
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
            ->searchPlaceholder('Cari judul, nomor, pihak lawan, badan usaha, atau file')
            ->columns([
                TextColumn::make('title')->label('Dokumen')->searchable(['title', 'document_number', 'counterparty'])->sortable()->weight('medium')->limit(45)->wrap()->width('28%')->tooltip(fn (Document $record): string => $record->title)
                    ->description(fn (Document $record): ?string => $record->document_number),
                TextColumn::make('company.name')->label('Badan Usaha')->searchable()->sortable()->limit(30)->wrap(),
                TextColumn::make('documentType.name')->label('Jenis Dokumen')->sortable()->limit(28)->wrap(),
                TextColumn::make('currentVersion.expiry_date')->label('Berakhir')->date('d M Y')->placeholder('Tanpa batas')->sortable(),
                TextColumn::make('expiry_status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => Document::EXPIRY_STATUSES[$state])
                    ->color(fn (string $state): string => Document::EXPIRY_STATUS_COLORS[$state]),
                TextColumn::make('counterparty')->label('Pihak Lawan')->searchable()->toggleable(isToggledHiddenByDefault: true)->limit(30)->wrap()->placeholder('-'),
                TextColumn::make('pic.name')->label('PIC')->toggleable(isToggledHiddenByDefault: true)->limit(30)->wrap(),
                TextColumn::make('currentVersion.file_name')->label('Nama File')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company')->label('Badan Usaha')->relationship('company', 'name')->searchable()->preload(),
                SelectFilter::make('document_type')->label('Jenis Dokumen')->relationship('documentType', 'name')->searchable()->preload(),
                SelectFilter::make('expiry_status')->label('Status')->options(Document::EXPIRY_STATUSES)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->withExpiryStatus($data['value']) : $query),
                SelectFilter::make('pic')->label('PIC')->relationship('pic', 'name')->searchable()->preload(),
                Filter::make('counterparty')->label('Pihak Lawan')
                    ->schema([TextInput::make('counterparty')->label('Pihak Lawan')->maxLength(255)->placeholder('Sebagian nama pihak lawan')])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['counterparty'] ?? null)
                        ? $query->whereRaw("lower(documents.counterparty) like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim((string) $data['counterparty']))).'%'])
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['counterparty'] ?? null) ? 'Pihak lawan: '.trim((string) $data['counterparty']) : null),
                Filter::make('expiry_range')->label('Tanggal Berakhir')
                    ->schema([
                        DatePicker::make('expiry_from')->label('Berakhir dari')->native(false),
                        DatePicker::make('expiry_until')->label('Berakhir sampai')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['expiry_from'] ?? null) || filled($data['expiry_until'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas('currentVersion', fn (Builder $version): Builder => $version
                            ->when(filled($data['expiry_from'] ?? null), fn (Builder $version): Builder => $version->whereDate('expiry_date', '>=', $data['expiry_from']))
                            ->when(filled($data['expiry_until'] ?? null), fn (Builder $version): Builder => $version->whereDate('expiry_date', '<=', $data['expiry_until'])))
                    ))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        filled($data['expiry_from'] ?? null) ? 'Berakhir dari '.CarbonImmutable::parse($data['expiry_from'])->format('d M Y') : null,
                        filled($data['expiry_until'] ?? null) ? 'Berakhir sampai '.CarbonImmutable::parse($data['expiry_until'])->format('d M Y') : null,
                    ]))),
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
                ViewAction::make()->label('Lihat')->iconButton()->tooltip('Lihat dokumen'),
                EditAction::make()->label('Ubah')->iconButton()->tooltip('Ubah dokumen'),
                Action::make('download')->label('Unduh')->icon('heroicon-o-arrow-down-tray')
                    ->iconButton()->tooltip('Unduh file')
                    ->url(fn (Document $record): string => route('documents.download', $record))->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => Gate::allows('view', $record)),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(fn (ListDocuments $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->getActiveFiltersCount() > 0
                ? 'Dokumen tidak ditemukan' : 'Belum ada dokumen')
            ->emptyStateDescription(fn (ListDocuments $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->getActiveFiltersCount() > 0
                ? 'Ubah kata pencarian atau reset filter untuk melihat dokumen lainnya.' : 'Tambahkan dokumen legal untuk mulai mengarsipkan.');
    }
}
