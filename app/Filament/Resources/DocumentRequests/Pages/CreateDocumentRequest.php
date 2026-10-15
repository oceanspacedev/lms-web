<?php

namespace App\Filament\Resources\DocumentRequests\Pages;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Models\DocumentRequest;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDocumentRequest extends CreateRecord
{
    protected static string $resource = DocumentRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DocumentRequest::saveDraft($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return DocumentRequestResource::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Draf');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->hidden();
    }
}
