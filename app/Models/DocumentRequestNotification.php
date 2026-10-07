<?php

namespace App\Models;

use Database\Factories\DocumentRequestNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentRequestNotification extends Model
{
    /** @use HasFactory<DocumentRequestNotificationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'last_attempt_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
