<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Concerns\HasBackToListAction;
use App\Filament\Concerns\ShowsTrashedRecordReadOnly;
use App\Filament\Concerns\TrashActions;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    use HasBackToListAction;
    use ShowsTrashedRecordReadOnly;

    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->backToListAction(),
            TrashActions::restore('badan usaha'),
            TrashActions::forceDelete('badan usaha'),
            TrashActions::delete('badan usaha'),
        ];
    }
}
