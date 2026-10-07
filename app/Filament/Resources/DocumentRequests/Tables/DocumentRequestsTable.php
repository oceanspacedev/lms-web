<?php

namespace App\Filament\Resources\DocumentRequests\Tables;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Models\DocumentRequest;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Pengajuan')->searchable()->description(fn (DocumentRequest $record): string => '#'.$record->id.' · '.$record->documentType->name),
            TextColumn::make('partner_name')->label('Mitra')->searchable(),
            TextColumn::make('requester_name')->label('Pengaju')->state(fn (DocumentRequest $record): string => $record->applicantName()),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => DocumentRequest::STATUSES[$state])->color(fn (string $state): string => match ($state) {
                'archived' => 'success', 'rejected' => 'danger', 'revision' => 'warning', 'draft' => 'gray', default => 'info'
            }),
            TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d M Y')->sortable(),
        ])->filters([SelectFilter::make('status')->label('Status')->options(DocumentRequest::STATUSES)])
            ->recordActions([Action::make('open')->label('Buka')->url(fn (DocumentRequest $record): string => DocumentRequestResource::getUrl('edit', ['record' => $record]))])->defaultSort('updated_at', 'desc')->emptyStateHeading('Belum ada pengajuan')->emptyStateDescription(null);
    }
}
