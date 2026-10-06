<?php

namespace App\Models;

use Database\Factories\ReminderLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_version_id', 'offset_days', 'recipient_phone', 'status', 'attempts', 'request_payload', 'provider_response', 'error_message', 'last_attempt_at', 'sent_at'])]
class ReminderLog extends Model
{
    /** @use HasFactory<ReminderLogFactory> */
    use HasFactory;

    public const STATUSES = ['pending' => 'Diproses', 'sent' => 'Terkirim', 'accepted' => 'Diterima WagHub', 'failed' => 'Gagal', 'cancelled' => 'Dibatalkan'];

    protected function casts(): array
    {
        return ['request_payload' => 'array', 'provider_response' => 'array', 'last_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }
}
