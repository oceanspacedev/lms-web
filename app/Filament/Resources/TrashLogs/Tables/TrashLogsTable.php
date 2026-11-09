<?php

namespace App\Filament\Resources\TrashLogs\Tables;

use App\Models\TrashLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TrashLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table->striped()->paginated([10, 25, 50])->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->timezone(config('lms.reminder_timezone')),
                TextColumn::make('subject_type')->label('Jenis data')->formatStateUsing(fn (string $state): string => TrashLog::SUBJECTS[$state] ?? $state),
                TextColumn::make('subject_label')->label('Nama')->searchable()->wrap(),
                TextColumn::make('action')->label('Tindakan')->badge()->formatStateUsing(fn (string $state): string => TrashLog::ACTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'deleted' => 'warning', 'restored' => 'success', 'force_deleted' => 'danger', default => 'gray'
                    }),
                // Pelaku di tempat sampah diberi tanda "(dihapus)". User yang sudah dihapus permanen tidak ada lagi; namanya tersimpan di log saat tindakan dilakukan.
                TextColumn::make('user.name')->label('Oleh')->placeholder('-')
                    ->state(fn (TrashLog $record): ?string => $record->user?->displayName() ?? $record->details['actor_name'] ?? null),
                TextColumn::make('details')->label('Keterangan')->wrap()->placeholder('-')
                    ->state(fn (TrashLog $record): ?string => self::remarks($record)),
            ])
            ->filters([
                SelectFilter::make('subject_type')->label('Jenis data')->options(TrashLog::SUBJECTS),
                SelectFilter::make('action')->label('Tindakan')->options(TrashLog::ACTIONS),
            ])
            ->recordActions([])->toolbarActions([]);
    }

    /**
     * Keterangan satu baris: data yang ikut terhapus permanen bersama induknya ("Ikut menghapus permanen: 3 dokumen, 1 pengajuan."
     * pada induknya, dari `details.cascaded`), dan nama file yang gagal dihapus dari penyimpanan, tanpa path lengkapnya.
     */
    private static function remarks(TrashLog $log): ?string
    {
        $details = $log->details ?? [];
        $remarks = [];
        if (isset($details['via']['subject'])) {
            $remarks[] = 'Ikut terhapus bersama '.(TrashLog::SUBJECTS[$details['via']['subject']] ?? $details['via']['subject']).' "'.($details['via']['label'] ?? '').'".';
        }
        if (! empty($details['cascaded'])) {
            $counts = collect($details['cascaded'])->groupBy('label')->map(fn (Collection $entries, string $label): string => $entries->sum('count').' '.$label)->implode(', ');
            $remarks[] = "Ikut menghapus permanen: {$counts}.";
        }
        if (! empty($details['left_files'])) {
            $names = collect($details['left_files'])->map(fn (array $file): string => basename($file['path']))->implode(', ');
            $remarks[] = "File tertinggal di penyimpanan: {$names}; hubungi admin IT.";
        }

        return $remarks === [] ? null : implode(' ', $remarks);
    }
}
