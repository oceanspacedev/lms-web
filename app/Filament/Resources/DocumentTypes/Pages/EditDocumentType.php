<?php

namespace App\Filament\Resources\DocumentTypes\Pages;

use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use Filament\Resources\Pages\EditRecord;

class EditDocumentType extends EditRecord
{
    protected static string $resource = DocumentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
