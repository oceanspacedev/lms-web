<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Ubah Informasi'),
            Action::make('download')->label('Unduh')->icon('heroicon-o-arrow-down-tray')
                ->url(fn (): string => route('documents.download', $this->getRecord()))->openUrlInNewTab(),
            Action::make('preview')->label('Pratinjau')->icon('heroicon-o-eye')
                ->url(fn (): string => route('documents.download', ['document' => $this->getRecord(), 'preview' => 1]))->openUrlInNewTab()
                ->visible(fn (): bool => in_array($this->getRecord()->currentVersion?->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true)),
        ];
    }
}
