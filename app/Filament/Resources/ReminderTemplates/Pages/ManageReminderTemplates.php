<?php

namespace App\Filament\Resources\ReminderTemplates\Pages;

use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReminderTemplates extends ManageRecords
{
    protected static string $resource = ReminderTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Atur Pengingat')->modalWidth('2xl')->createAnother(false)
                ->visible(fn (): bool => ReminderTemplateResource::canCreate()),
        ];
    }
}
