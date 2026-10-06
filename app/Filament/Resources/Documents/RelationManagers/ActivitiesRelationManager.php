<?php

namespace App\Filament\Resources\Documents\RelationManagers;

use App\Models\DocumentActivity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Riwayat Aktivitas';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    #[On('document-version-updated')]
    public function refreshActivities(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table->heading('Riwayat Aktivitas')->description('Jejak unggah, perubahan informasi, dan permintaan akses file.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'version']))
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d/m/Y H:i')->timezone('Asia/Jakarta')->sortable(),
                TextColumn::make('event')->label('Aktivitas')->formatStateUsing(fn (string $state): string => DocumentActivity::EVENTS[$state] ?? $state),
                TextColumn::make('user.name')->label('Pengguna')->placeholder('Sistem / pengguna tidak tersedia'),
                TextColumn::make('version.version_number')->label('Versi')->prefix('v')->placeholder('-'),
                TextColumn::make('version.file_name')->label('File')->wrap(),
            ])->defaultSort('id', 'desc')->paginationPageOptions([5, 10, 25])->defaultPaginationPageOption(5)
            ->emptyStateHeading('Belum ada aktivitas')->emptyStateDescription('Aktivitas baru akan dicatat setelah fitur ini diaktifkan.')
            ->headerActions([])->recordActions([])->toolbarActions([]);
    }
}
