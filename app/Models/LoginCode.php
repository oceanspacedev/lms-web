<?php

namespace App\Models;

use Database\Factories\LoginCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'phone', 'code_hash', 'expires_at', 'attempts', 'consumed_at', 'status', 'error_message', 'ip_address'])]
#[Hidden(['code_hash'])]
class LoginCode extends Model
{
    /** @use HasFactory<LoginCodeFactory> */
    use HasFactory, Prunable;

    public const STATUSES = ['pending' => 'Diproses', 'sent' => 'Terkirim', 'failed' => 'Gagal', 'expired' => 'Hangus', 'used' => 'Terpakai'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * Kode yang masih bisa dipakai: terkirim, belum dipakai, dan belum kedaluwarsa.
     *
     * @param  Builder<LoginCode>  $query
     * @return Builder<LoginCode>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', 'sent')->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    /** @return Builder<LoginCode> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(7));
    }
}
