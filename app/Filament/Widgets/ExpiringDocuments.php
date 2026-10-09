<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentSpreadsheetExporter;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ExpiringDocuments extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Document::class);
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Berakhir dalam 30 Hari')
            ->description('Dokumen yang berakhir hari ini hingga 30 hari ke depan, diurutkan dari tanggal terdekat.')
            ->query(function (): Builder {
                abort_unless(static::canView(), 403);

                return Document::query()->expiringWithin(30)->with(['company', 'documentType', 'currentVersion', 'pic'])
                    ->orderBy(DocumentVersion::query()->select('expiry_date')->whereColumn('id', 'documents.current_version_id')->limit(1))
                    ->orderBy('documents.id');
            })
            ->columns([
                TextColumn::make('title')->label('Judul')->searchable(),
                TextColumn::make('company.name')->label('Badan Usaha'),
                TextColumn::make('documentType.name')->label('Jenis Dokumen'),
                TextColumn::make('currentVersion.expiry_date')->label('Tanggal Berakhir')->date('d/m/Y'),
                TextColumn::make('expiry_status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => Document::EXPIRY_STATUSES[$state])
                    ->color(fn (string $state): string => Document::EXPIRY_STATUS_COLORS[$state]),
                TextColumn::make('pic.name')->label('PIC'),
            ])
            ->headerActions([
                Action::make('exportExcel')->label('Ekspor Excel')->icon('heroicon-o-table-cells')->color('gray')
                    ->action(function (self $livewire) {
                        abort_unless(static::canView(), 403);

                        return app(DocumentSpreadsheetExporter::class)->download($livewire->getTableQueryForExport(), 'dokumen-berakhir-30-hari');
                    }),
            ])
            ->recordActions([
                Action::make('view')->label('Lihat Dokumen')->icon('heroicon-o-eye')
                    ->url(fn (Document $record): string => DocumentResource::getUrl('view', ['record' => $record]))
                    ->visible(fn (Document $record): bool => Gate::allows('view', $record)),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Tidak ada dokumen yang berakhir dalam 30 hari')
            ->emptyStateDescription('Dokumen tanpa masa tenggang dan yang sudah kedaluwarsa tidak masuk daftar ini.');
    }
}
