<?php

namespace App\Filament\Resources\ReminderLogs\Tables;

use App\Models\ReminderLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReminderLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table->striped()->paginated([10, 25, 50])->defaultSort('id', 'desc')->columns([
            TextColumn::make('documentVersion.document.title')->label('Dokumen')->searchable()->limit(45),
            TextColumn::make('documentVersion.document.company.name')->label('Badan Usaha')->searchable(),
            TextColumn::make('documentVersion.version_number')->label('Versi')->prefix('v'),
            TextColumn::make('offset_days')->label('Pengingat')->formatStateUsing(fn (int $state): string => $state < 0 ? 'H+'.abs($state) : 'H-'.$state),
            TextColumn::make('recipient_phone')->label('WhatsApp')->placeholder('-'),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => ReminderLog::STATUSES[$state] ?? $state)
                ->color(fn (string $state): string => match ($state) {
                    'sent' => 'success', 'accepted' => 'info', 'failed' => 'danger', 'pending' => 'warning', default => 'gray'
                }),
            TextColumn::make('attempts')->label('Percobaan'),
            TextColumn::make('last_attempt_at')->label('Percobaan Terakhir')->dateTime('d M Y H:i')->timezone(config('lms.reminder_timezone'))->placeholder('-'),
        ])->filters([SelectFilter::make('status')->label('Status')->options(ReminderLog::STATUSES)])
            ->recordActions([ViewAction::make()->label('Lihat')->iconButton()->tooltip('Lihat log')])->toolbarActions([]);
    }
}
