<?php

namespace App\Filament\Auth\Concerns;

use App\Models\User;
use App\Services\WaghubService;

trait InteractsWithWhatsAppLogin
{
    /**
     * Nomor dinormalisasi ke format 62xxx, sama dengan users.phone.
     */
    protected function normalizeWhatsAppNumber(mixed $value): ?string
    {
        return app(WaghubService::class)->normalizePhone(is_scalar($value) ? (string) $value : null);
    }

    /**
     * Nomor yang dipakai lebih dari satu user aktif tidak bisa dipakai login.
     */
    protected function findEligibleWebUserByWhatsApp(string $number): ?User
    {
        $matches = User::active()
            ->where('phone', $number)
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $user = $matches->first();

        return $user && $this->canLoginToPanel($user) ? $user : null;
    }

    protected function userMatchesWhatsAppNumber(User $user, string $number): bool
    {
        return $user->isActive() && $user->phone === $number;
    }

    protected function unavailableWhatsAppMessage(): string
    {
        return 'Nomor WhatsApp belum terdaftar untuk login.';
    }
}
