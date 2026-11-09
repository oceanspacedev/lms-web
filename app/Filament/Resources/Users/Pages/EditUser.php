<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasBackToListAction;
use App\Filament\Concerns\ShowsTrashedRecordReadOnly;
use App\Filament\Concerns\TrashActions;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use HasBackToListAction;
    use ShowsTrashedRecordReadOnly;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->backToListAction(),
            TrashActions::restore('user'),
            TrashActions::forceDelete('user'),
            UserResource::deactivateAction(),
            UserResource::reactivateAction(),
            TrashActions::delete('user'),
        ];
    }
}
