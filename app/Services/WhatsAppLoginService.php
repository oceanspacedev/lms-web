<?php

namespace App\Services;

use App\Models\LoginCode;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Login panel dengan kode OTP yang dikirim ke WhatsApp lewat WagHub.
 */
class WhatsAppLoginService
{
    public const CODE_LENGTH = 6;

    public const TTL_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const MAX_CODES_PER_HOUR = 5;

    public const MAX_REQUESTS_PER_IP = 10;

    public const MESSAGE = 'Kode masuk LMS Legal: %s. Berlaku %d menit. Jangan bagikan kode ini kepada siapa pun.';

    public function __construct(private WaghubService $waghub) {}

    /**
     * Kirim kode ke nomor yang terdaftar. Nomor yang tidak terdaftar, dipakai lebih dari satu user,
     * atau milik user tanpa akses panel diperlakukan sama agar nomor terdaftar tidak bisa ditebak.
     */
    public function requestCode(string $rawPhone, ?string $ip): void
    {
        $phone = $this->normalize($rawPhone, 'phone');
        $ipKey = 'whatsapp-login-ip:'.($ip ?? 'unknown');
        $resendKey = 'whatsapp-login-resend:'.$phone;
        $hourKey = 'whatsapp-login-hour:'.$phone;

        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_REQUESTS_PER_IP)) {
            throw ValidationException::withMessages(['phone' => 'Terlalu banyak permintaan kode. Coba lagi dalam '.ceil(RateLimiter::availableIn($ipKey) / 60).' menit.']);
        }
        if (RateLimiter::tooManyAttempts($resendKey, 1)) {
            throw ValidationException::withMessages(['phone' => 'Tunggu '.RateLimiter::availableIn($resendKey).' detik sebelum meminta kode baru.']);
        }
        if (RateLimiter::tooManyAttempts($hourKey, self::MAX_CODES_PER_HOUR)) {
            throw ValidationException::withMessages(['phone' => 'Batas permintaan kode untuk nomor ini tercapai. Coba lagi dalam '.ceil(RateLimiter::availableIn($hourKey) / 60).' menit atau masuk dengan email.']);
        }

        $lock = Cache::lock('whatsapp-login-lock:'.$phone, 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['phone' => 'Kode sedang dikirim. Tunggu sebentar.']);
        }

        try {
            RateLimiter::hit($ipKey, 600);
            RateLimiter::hit($resendKey, self::RESEND_SECONDS);
            RateLimiter::hit($hourKey, 3600);

            $user = $this->findUser($phone);
            if ($user === null) {
                return;
            }

            LoginCode::where('phone', $phone)->whereIn('status', ['pending', 'sent'])->whereNull('consumed_at')->update(['status' => 'expired']);
            $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
            $loginCode = LoginCode::create([
                'user_id' => $user->id, 'phone' => $phone, 'code_hash' => LoginCode::hashCode($code),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES), 'status' => 'pending', 'ip_address' => $ip,
            ]);

            $error = $this->deliver($loginCode, $code);
            $loginCode->update(['status' => $error === null ? 'sent' : 'failed', 'error_message' => $error]);

            if ($error !== null) {
                RateLimiter::clear($resendKey);
                throw ValidationException::withMessages(['phone' => 'Kode belum bisa dikirim. Coba lagi atau masuk dengan email.']);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Cocokkan kode dan kembalikan user bila benar. Kode langsung ditandai terpakai.
     */
    public function verifyCode(string $rawPhone, string $code): User
    {
        $phone = $this->normalize($rawPhone, 'code');
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^[0-9]{'.self::CODE_LENGTH.'}$/', $code)) {
            throw ValidationException::withMessages(['code' => 'Masukkan '.self::CODE_LENGTH.' digit kode dari WhatsApp.']);
        }

        $invalid = 'Kode salah atau sudah tidak berlaku. Minta kode baru.';

        // Galat dilempar setelah transaksi selesai agar jumlah percobaan yang salah tetap tersimpan.
        $result = DB::transaction(function () use ($phone, $code, $invalid): User|string {
            $loginCode = LoginCode::usable()->where('phone', $phone)->latest('id')->lockForUpdate()->first();
            if ($loginCode === null) {
                return $invalid;
            }

            if (! hash_equals($loginCode->code_hash, LoginCode::hashCode($code))) {
                $attempts = $loginCode->attempts + 1;
                $exhausted = $attempts >= self::MAX_ATTEMPTS;
                $loginCode->update(['attempts' => $attempts, 'status' => $exhausted ? 'expired' : $loginCode->status]);

                return $exhausted
                    ? 'Kode hangus karena terlalu banyak percobaan. Minta kode baru.'
                    : 'Kode salah. Sisa percobaan: '.(self::MAX_ATTEMPTS - $attempts).'.';
            }

            $loginCode->update(['consumed_at' => now(), 'status' => 'used']);
            $user = $loginCode->user;

            return $user !== null && $user->phone === $phone && $this->canAccessPanel($user) ? $user : $invalid;
        });

        if (is_string($result)) {
            throw ValidationException::withMessages(['code' => $result]);
        }

        return $result;
    }

    public function secondsUntilResend(string $rawPhone): int
    {
        $phone = $this->waghub->normalizePhone($rawPhone);
        $key = 'whatsapp-login-resend:'.$phone;

        return $phone !== null && RateLimiter::tooManyAttempts($key, 1) ? RateLimiter::availableIn($key) : 0;
    }

    private function normalize(string $rawPhone, string $field): string
    {
        $phone = $this->waghub->normalizePhone($rawPhone);
        if ($phone === null) {
            throw ValidationException::withMessages([$field => 'Masukkan nomor WhatsApp Indonesia yang valid.']);
        }

        return $phone;
    }

    private function findUser(string $phone): ?User
    {
        $users = User::active()->where('phone', $phone)->limit(2)->get();

        return $users->count() === 1 && $this->canAccessPanel($users->first()) ? $users->first() : null;
    }

    private function canAccessPanel(User $user): bool
    {
        return $user->canAccessPanel(Filament::getPanel('admin'));
    }

    /**
     * Kirim pesan lewat WagHub dan kembalikan pesan galat, atau null bila diterima.
     */
    private function deliver(LoginCode $loginCode, string $code): ?string
    {
        $key = 'lms-login-'.$loginCode->id;

        try {
            $response = $this->waghub->send([
                'recipient' => ['type' => 'phone', 'value' => $loginCode->phone],
                'message' => ['type' => 'text', 'text' => sprintf(self::MESSAGE, $code, self::TTL_MINUTES)],
                'purpose' => config('services.waghub.otp_purpose'), 'mode' => config('services.waghub.mode'),
                'route_key' => config('services.waghub.route_key'), 'expires_at' => $loginCode->expires_at->toIso8601String(),
                'client_reference' => $key,
            ], $key);

            return $response->successful() ? null : 'WagHub menolak pesan (HTTP '.$response->status().').';
        } catch (ConnectionException) {
            return 'Koneksi WagHub gagal.';
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
    }
}
