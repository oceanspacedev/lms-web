<?php

namespace App\Filament\Resources\ReminderLogs\Pages;

use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReminderLog extends ViewRecord
{
    protected static string $resource = ReminderLogResource::class;

    protected function getHeaderActions(): array
    {
        return [ReminderLogResource::resendAction()->record($this->getRecord())];
    }
}
