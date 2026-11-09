<?php

namespace App\Filament\Concerns;

use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Halaman ubah yang juga dipakai super admin untuk membuka data di tempat sampah (spec §3.3): judul "Detail {nama}",
 * pemberitahuan tempat sampah di atas, formulir hanya-baca, dan tanpa tombol simpan.
 * Pengguna lain tidak sampai ke sini, karena route binding-nya 404 (TrashActions::routeBindingQuery).
 */
trait ShowsTrashedRecordReadOnly
{
    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->disabled(fn (): bool => $this->getRecord()->trashed());
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->trashed() ? 'Detail '.$this->getRecordTitle() : parent::getTitle();
    }

    public function getBreadcrumb(): string
    {
        return $this->getRecord()->trashed() ? 'Detail' : parent::getBreadcrumb();
    }

    public function getFormContentComponent(): Component
    {
        return Group::make([
            Callout::make(fn (): string => TrashActions::trashedNotice($this->getRecord()))->warning()
                ->visible(fn (): bool => $this->getRecord()->trashed()),
            parent::getFormContentComponent(),
        ]);
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        abort_if($this->getRecord()->trashed(), 403);

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    protected function getFormActions(): array
    {
        return $this->getRecord()->trashed() ? [] : parent::getFormActions();
    }
}
