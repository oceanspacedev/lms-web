<?php

namespace App\Filament\Resources\ReminderLogs\Pages;

use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Models\ReminderLog;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListReminderLogs extends ListRecords
{
    protected static string $resource = ReminderLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('delivery_history')->label('Riwayat pengiriman WhatsApp')->color('gray')->outlined()->icon(Heroicon::OutlinedClock)
                ->visible(fn (): bool => ReminderTemplateResource::canAccess())
                ->url(fn (): string => ReminderTemplateResource::getUrl('history', $this->deliveryHistoryFilters())),
        ];
    }

    /**
     * Filter riwayat pengiriman yang dibawa dari tabel ini: selalu jenis pengingat masa berlaku, plus status bila sedang difilter.
     *
     * @return array{source: string, deliveryStatus?: string}
     */
    private function deliveryHistoryFilters(): array
    {
        $status = $this->tableFilters['status']['value'] ?? null;

        return ['source' => 'reminder', ...(is_string($status) && array_key_exists($status, ReminderLog::STATUSES) ? ['deliveryStatus' => $status] : [])];
    }
}
