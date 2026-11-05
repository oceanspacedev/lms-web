<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ForceDeleteAction;
use Filament\Support\Icons\Heroicon;

/**
 * Tombol "Kembali" di header halaman detail/ubah, menuju daftar resource-nya. Untuk data di tempat sampah, daftar itu
 * tersaring "Hanya data dihapus", begitu pula tujuan setelah hapus permanen dari halaman ini.
 */
trait HasBackToListAction
{
    protected function backToListAction(): Action
    {
        return Action::make('back')->label('Kembali')->icon(Heroicon::OutlinedArrowLeft)->color('gray')
            ->url(fn (): string => $this->isShowingTrashedRecord() ? TrashActions::trashIndexUrl(static::getResource()) : static::getResource()::getUrl('index'));
    }

    public function getDefaultActionSuccessRedirectUrl(Action $action): ?string
    {
        return $action instanceof ForceDeleteAction ? TrashActions::trashIndexUrl(static::getResource()) : parent::getDefaultActionSuccessRedirectUrl($action);
    }

    private function isShowingTrashedRecord(): bool
    {
        $record = $this->getRecord();

        return method_exists($record, 'trashed') && $record->trashed();
    }
}
