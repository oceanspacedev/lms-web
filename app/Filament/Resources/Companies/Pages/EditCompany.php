<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Concerns\HasBackToListAction;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    use HasBackToListAction;

    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->backToListAction(),
        ];
    }
}
