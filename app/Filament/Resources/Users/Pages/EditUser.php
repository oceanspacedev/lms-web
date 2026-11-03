<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasBackToListAction;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use HasBackToListAction;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->backToListAction(),
            UserResource::deactivateAction(),
            UserResource::reactivateAction(),
        ];
    }
}
