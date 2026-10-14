<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** User nonaktif tidak bisa masuk (password maupun OTP) dan sesi yang masih terbuka ditolak oleh middleware panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->isActive()
            && ($this->roles()->exists() || $this->getAllPermissions()->isNotEmpty());
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('filament-shield.super_admin.name'));
    }

    /** @param Builder<User> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('deactivated_at'));
    }

    /**
     * User aktif, ditambah user yang sedang tersimpan pada form (walau sudah nonaktif) agar data lama tetap bisa disimpan.
     *
     * @param  Builder<User>  $query
     */
    public function scopeActiveOrKeep(Builder $query, int|string|null $keepId): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query->whereNull($query->qualifyColumn('deactivated_at'))
            ->when(filled($keepId), fn (Builder $query): Builder => $query->orWhere($query->qualifyColumn('id'), $keepId)));
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'deactivated_by');
    }

    /**
     * Nomor WhatsApp dipakai untuk login OTP, jadi satu nomor hanya boleh milik satu user aktif.
     * User nonaktif diabaikan supaya nomornya bisa dipakai staf baru.
     */
    public static function phoneIsTaken(string $normalizedPhone, int|string|null $ignoreId = null): bool
    {
        return static::active()->where('phone', $normalizedPhone)->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
