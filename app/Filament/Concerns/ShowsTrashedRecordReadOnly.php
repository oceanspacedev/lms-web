<?php

namespace App\Filament\Concerns;

use Filament\Schemas\Schema;

/**
 * Halaman ubah yang juga dipakai super admin untuk membuka data di tempat sampah (spec §3.3): formulir hanya-baca
 * dan tanpa tombol simpan. Pengguna lain tidak sampai ke sini, karena route binding-nya 404 (TrashActions::routeBindingQuery).
 */
trait ShowsTrashedRecordReadOnly
{
    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->disabled(fn (): bool => $this->getRecord()->trashed());
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
