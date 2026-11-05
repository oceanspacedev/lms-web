<?php

namespace App\Models;

use Database\Factories\DocumentRequestNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequestNotification extends Model
{
    /** @use HasFactory<DocumentRequestNotificationFactory> */
    use HasFactory;

    /** Keterangan notifikasi antre yang dibatalkan karena pengajuannya dipindahkan ke tempat sampah. */
    public const REQUEST_TRASHED_MESSAGE = 'Pengajuan dipindahkan ke tempat sampah.';

    /** Keterangan tahap pengingat yang jatuh tempo selama pengajuan di tempat sampah; dicatat saat pulih dan tidak dikirim. */
    public const SKIPPED_WHILE_TRASHED_MESSAGE = 'Jatuh tempo saat pengajuan di tempat sampah, tidak dikirim.';

    /** Keterangan notifikasi antre yang dibatalkan karena penerimanya (user) dipindahkan ke tempat sampah. */
    public const RECIPIENT_TRASHED_MESSAGE = 'Penerima dipindahkan ke tempat sampah.';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'last_attempt_at' => 'datetime', 'accepted_at' => 'datetime', 'resent_at' => 'datetime'];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }
}
