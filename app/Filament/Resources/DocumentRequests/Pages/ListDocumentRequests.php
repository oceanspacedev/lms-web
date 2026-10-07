<?php

namespace App\Filament\Resources\DocumentRequests\Pages;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentRequests extends ListRecords
{
    protected static string $resource = DocumentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('public_form')->label('Form publik')->color('gray')->url(route('requests.public.create'))->openUrlInNewTab(),
            CreateAction::make()->label('Tambah Pengajuan'),
        ];
    }
}
