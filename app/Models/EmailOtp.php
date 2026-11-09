<?php

namespace App\Models;

use Database\Factories\EmailOtpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'email', 'purpose', 'otp_hash', 'token_hash', 'expires_at', 'verified_at', 'attempt_count'])]
#[Hidden(['otp_hash', 'token_hash'])]
class EmailOtp extends Model
{
    /** @use HasFactory<EmailOtpFactory> */
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

    /** @return Builder<EmailOtp> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(7));
    }
}
