<?php

namespace App\Filament\Resources\ReminderLogs\Pages;

use App\Filament\Concerns\HasBackToListAction;
use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReminderLog extends ViewRecord
{
    use HasBackToListAction;

    protected static string $resource = ReminderLogResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->backToListAction(), ReminderLogResource::resendAction()->record($this->getRecord())];
    }
}
