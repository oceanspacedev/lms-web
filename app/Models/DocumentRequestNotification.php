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
