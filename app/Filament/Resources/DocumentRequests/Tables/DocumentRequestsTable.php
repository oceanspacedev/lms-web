<?php

namespace App\Filament\Resources\DocumentRequests\Tables;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Models\DocumentRequest;
use App\Models\ReminderTemplate;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DocumentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table->striped()->paginated([10, 25, 50])->searchPlaceholder('Cari pengajuan atau mitra')->columns([
            TextColumn::make('title')->label('Pengajuan')->searchable()->weight('medium')->limit(45)->wrap()->tooltip(fn (DocumentRequest $record): string => $record->title)
                ->description(fn (DocumentRequest $record): string => $record->documentType->name.($record->revisionNumber() ? ' · Revisi ke-'.$record->revisionNumber() : '')),
            TextColumn::make('partner_name')->label('Mitra')->searchable()->limit(30)->wrap(),
            TextColumn::make('requester_name')->label('Pengaju')->limit(30)->wrap()->state(fn (DocumentRequest $record): string => $record->applicantName()),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => DocumentRequest::STATUSES[$state])->color(fn (string $state): string => match ($state) {
                'archived' => 'success', 'rejected' => 'danger', 'revision' => 'warning', 'draft' => 'gray', default => 'info'
            }),
            TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d M Y')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Status')->options(DocumentRequest::STATUSES),
            Filter::make('waiting_long')->toggle()
                ->label(fn (): string => 'Menunggu lebih dari '.ReminderTemplate::requestReminderSettings()['after_days'].' hari')
                ->query(fn (Builder $query): Builder => $query->waitingLongerThan(ReminderTemplate::requestReminderSettings()['after_days']))
                ->indicator('Menunggu lama'),
        ])
            ->recordActions([Action::make('open')->label('Buka')->icon('heroicon-o-eye')->iconButton()->tooltip('Buka pengajuan')->url(fn (DocumentRequest $record): string => DocumentRequestResource::getUrl('edit', ['record' => $record]))])->defaultSort('updated_at', 'desc')->emptyStateHeading('Belum ada pengajuan')->emptyStateDescription(null);
    }
}
