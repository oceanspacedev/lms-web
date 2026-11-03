<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Tombol "Kembali" di header halaman detail/ubah, menuju daftar resource-nya.
 */
trait HasBackToListAction
{
    protected function backToListAction(): Action
    {
        return Action::make('back')->label('Kembali')->icon(Heroicon::OutlinedArrowLeft)->color('gray')
            ->url(fn (): string => static::getResource()::getUrl('index'));
    }
}
