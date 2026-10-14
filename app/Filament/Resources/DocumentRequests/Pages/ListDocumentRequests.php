<?php

namespace App\Filament\Resources\DocumentRequests\Pages;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDocumentRequests extends ListRecords
{
    protected static string $resource = DocumentRequestResource::class;

    /** Widget dasbor kini memakai `?filters=` (yang dibaca halaman daftar Filament 5); tautan lama dengan `?tableFilters=` tetap diterima. */
    public function mount(): void
    {
        $legacyFilters = request()->query('tableFilters');
        if (blank($this->tableFilters) && is_array($legacyFilters)) {
            $this->tableFilters = $legacyFilters;
        }

        parent::mount();
    }

    /** @return array<string, Tab> Tab aktif tersimpan di query string (`?tab=`), jadi tautan dan muat ulang tetap di tab yang sama. */
    public function getTabs(): array
    {
        $actionableCount = null;

        return [
            'all' => Tab::make('Semua'),
            'actionable' => Tab::make('Perlu tindakan saya')
                ->modifyQueryUsing(fn (Builder $query): Builder => DocumentRequestResource::scopeToActionable($query))
                ->badge(function () use (&$actionableCount): string {
                    $actionableCount ??= DocumentRequestResource::countActionable();

                    return (string) $actionableCount;
                })
                ->badgeColor(fn (?string $badge): string => (int) $badge > 0 ? 'warning' : 'gray'),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'all';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('public_form')->label('Form publik')->color('gray')->url(route('requests.public.create'))->openUrlInNewTab(),
            CreateAction::make()->label('Tambah Pengajuan'),
        ];
    }
}
