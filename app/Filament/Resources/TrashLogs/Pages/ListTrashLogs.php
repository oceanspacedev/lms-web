<?php

namespace App\Filament\Resources\TrashLogs\Pages;

use App\Filament\Resources\TrashLogs\TrashLogResource;
use Filament\Resources\Pages\ListRecords;

class ListTrashLogs extends ListRecords
{
    protected static string $resource = TrashLogResource::class;
}
