<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat hapus, pulihkan, dan hapus permanen. Disimpan terpisah karena data yang dihapus permanen sudah tidak ada.
 */
#[Fillable(['subject_type', 'subject_id', 'subject_label', 'action', 'user_id', 'details'])]
class TrashLog extends Model
{
    /** Hanya ada created_at; riwayat tidak pernah diubah. */
    public const UPDATED_AT = null;

    public const ACTIONS = ['deleted' => 'Dihapus', 'restored' => 'Dipulihkan', 'force_deleted' => 'Dihapus permanen'];

    public const SUBJECTS = ['document' => 'Dokumen', 'company' => 'Badan Usaha', 'document_type' => 'Jenis Dokumen', 'user' => 'User', 'document_request' => 'Pengajuan'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
