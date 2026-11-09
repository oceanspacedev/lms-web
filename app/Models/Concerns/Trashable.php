<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Data yang bisa masuk tempat sampah: soft delete, pencatat penghapusan (`deleted_by`), dan nama untuk log serta tampilan.
 * Model wajib menentukan `trashSubject()` (kunci TrashLog::SUBJECTS) dan `trashLabel()`.
 */
trait Trashable
{
    use SoftDeletes;

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    /** Kunci jenis data pada TrashLog::SUBJECTS. */
    abstract public function trashSubject(): string;

    /** Nama atau judul data, untuk log dan pesan. */
    abstract public function trashLabel(): string;

    /** Nama yang ditampilkan di tabel dan riwayat: diberi tanda "(dihapus)" bila data ada di tempat sampah. */
    public function displayName(): string
    {
        return $this->trashLabel().($this->trashed() ? ' (dihapus)' : '');
    }
}
