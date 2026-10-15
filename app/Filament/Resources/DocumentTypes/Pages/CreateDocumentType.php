<?php

namespace App\Filament\Resources\DocumentTypes\Pages;

use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentType extends CreateRecord
{
    protected static string $resource = DocumentTypeResource::class;
}
