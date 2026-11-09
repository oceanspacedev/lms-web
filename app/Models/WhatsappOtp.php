<?php

namespace App\Models;

use Database\Factories\WhatsappOtpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'whatsapp_number', 'purpose', 'otp_hash', 'expires_at', 'verified_at', 'attempt_count'])]
#[Hidden(['otp_hash'])]
class WhatsappOtp extends Model
{
    /** @use HasFactory<WhatsappOtpFactory> */
    use HasFactory, Prunable;

    public const PURPOSE_LOGIN = 'login';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Builder<WhatsappOtp> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(7));
    }
}
