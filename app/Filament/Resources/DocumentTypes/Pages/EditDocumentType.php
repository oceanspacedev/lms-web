<?php

namespace App\Filament\Resources\DocumentTypes\Pages;

use App\Filament\Concerns\HasBackToListAction;
use App\Filament\Concerns\ShowsTrashedRecordReadOnly;
use App\Filament\Concerns\TrashActions;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use Filament\Resources\Pages\EditRecord;

class EditDocumentType extends EditRecord
{
    use HasBackToListAction;
    use ShowsTrashedRecordReadOnly;

    protected static string $resource = DocumentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->backToListAction(),
            TrashActions::restore('jenis dokumen'),
            TrashActions::forceDelete('jenis dokumen'),
            TrashActions::delete('jenis dokumen'),
        ];
    }
}
