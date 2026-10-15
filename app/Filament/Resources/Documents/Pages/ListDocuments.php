<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    #[Url(as: 'view', except: 'table')]
    public string $documentView = 'table';

    public function isGridView(): bool
    {
        return $this->documentView === 'grid';
    }

    public function setDocumentView(string $view): void
    {
        abort_unless(in_array($view, ['table', 'grid'], true), 422);

        $this->documentView = $view;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label('Impor')->color('gray')->icon(Heroicon::OutlinedArrowUpTray)
                ->url(DocumentResource::getUrl('import'))
                ->visible(fn (): bool => ImportDocuments::canAccess()),
            CreateAction::make()->label('Tambah Dokumen'),
        ];
    }
}
