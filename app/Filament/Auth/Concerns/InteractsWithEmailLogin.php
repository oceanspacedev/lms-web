<?php

namespace App\Filament\Auth\Concerns;

use App\Models\User;
use Illuminate\Support\Str;

trait InteractsWithEmailLogin
{
    protected function normalizeEmail(mixed $value): ?string
    {
        $email = Str::lower(trim(is_scalar($value) ? (string) $value : ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * Email yang (tanpa membedakan huruf besar/kecil) dipakai lebih dari satu user aktif tidak bisa dipakai login.
     */
    protected function findEligibleWebUserByEmail(string $email): ?User
    {
        $matches = User::active()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $user = $matches->first();

        return $user && $this->canLoginToPanel($user) ? $user : null;
    }

    protected function userMatchesEmail(User $user, string $email): bool
    {
        return $user->isActive()
            && ! $user->trashed()
            && filled($user->email)
            && Str::lower(trim((string) $user->email)) === $email;
    }

    protected function unavailableEmailMessage(): string
    {
        return 'Email belum terdaftar untuk login.';
    }
}
