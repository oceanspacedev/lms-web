<?php

namespace App\Filament\Resources\Documents\RelationManagers;

use App\Models\DocumentVersion;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\On;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Riwayat Versi';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    #[On('document-version-updated')]
    public function refreshVersions(): void
    {
        $this->getOwnerRecord()->refresh();
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Riwayat Versi')
            ->description('Versi terbaru ditampilkan lebih dahulu. File versi sebelumnya tetap dapat diunduh.')
            ->recordTitleAttribute('version_number')
            ->columns([
                TextColumn::make('version_number')
                    ->label('Versi')->prefix('v')->sortable(),
                TextColumn::make('is_current')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Versi aktif' : 'Arsip')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('file_name')->label('File')->searchable()->wrap()
                    ->description(fn (DocumentVersion $record): string => Number::fileSize($record->file_size, precision: 1)),
                TextColumn::make('issued_date')->label('Tanggal Terbit')->date('d/m/Y')->placeholder('Belum diisi')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expiry_date')->label('Tanggal Berakhir')->date('d/m/Y')->placeholder('Tanpa Masa Berlaku')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uploader.name')->label('Pengunggah')->wrap()
                    ->description(fn (DocumentVersion $record): string => $record->created_at->format('d/m/Y H:i')),
                TextColumn::make('change_note')->label('Catatan Perubahan')->wrap()->limit(80)
                    ->tooltip(fn (DocumentVersion $record): ?string => $record->change_note)->placeholder('Unggahan awal'),
            ])
            ->recordActions([
                Action::make('download')->label('Unduh')->icon('heroicon-o-arrow-down-tray')
                    ->authorize(fn (): bool => Gate::allows('view', $this->getOwnerRecord()))
                    ->url(fn (DocumentVersion $record): string => route('documents.download', ['document' => $this->getOwnerRecord(), 'version' => $record->id]))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('version_number', 'desc')
            ->paginationPageOptions([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
