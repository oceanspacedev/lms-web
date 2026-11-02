<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsappOtp;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Kode OTP login WhatsApp yang dikirim lewat WagHub.
 */
class WhatsAppOtpService
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private WaghubService $waghub) {}

    /**
     * @return array{otp_record: WhatsappOtp, expires_in: int}
     */
    public function issue(User $user, string $number, string $purpose): array
    {
        $expiresIn = max(60, (int) config('services.waghub.otp_expires_in', 300));
        $lockSeconds = max(30, (int) config('services.waghub.timeout', 20) + 10);
        $lockKey = 'whatsapp-otp:issue:'.hash('sha256', implode('|', [
            (string) $user->getKey(),
            $number,
            $purpose,
        ]));

        return Cache::lock($lockKey, $lockSeconds)->block(10, function () use (
            $user,
            $number,
            $purpose,
            $expiresIn,
        ): array {
            $expiresAt = now()->addSeconds($expiresIn);
            $otp = (string) random_int(100000, 999999);

            $otpRecord = DB::transaction(function () use (
                $user,
                $number,
                $purpose,
                $otp,
                $expiresAt,
            ): WhatsappOtp {
                WhatsappOtp::query()
                    ->where('whatsapp_number', $number)
                    ->where('purpose', $purpose)
                    ->whereNull('verified_at')
                    ->update(['expires_at' => now()]);

                return WhatsappOtp::query()->create([
                    'user_id' => $user->id,
                    'whatsapp_number' => $number,
                    'purpose' => $purpose,
                    'otp_hash' => Hash::make($otp),
                    'expires_at' => $expiresAt,
                    'attempt_count' => 0,
                ]);
            });

            try {
                $this->send($otpRecord, $otp, $expiresIn);
            } catch (Throwable $exception) {
                $otpRecord->forceFill(['expires_at' => now()])->save();

                Log::warning('Gagal mengirim OTP WhatsApp', [
                    'user_id' => $user->id,
                    'purpose' => $purpose,
                    'whatsapp_last4' => substr($number, -4),
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                throw new RuntimeException('Gagal mengirim OTP WhatsApp.', previous: $exception);
            }

            return [
                'otp_record' => $otpRecord,
                'expires_in' => $expiresIn,
            ];
        });
    }

    public function verify(User $user, string $number, string $purpose, string $otp): ?WhatsappOtp
    {
        return DB::transaction(function () use ($user, $number, $purpose, $otp): ?WhatsappOtp {
            $otpRecord = WhatsappOtp::query()
                ->where('whatsapp_number', $number)
                ->where('purpose', $purpose)
                ->where('user_id', $user->id)
                ->whereNull('verified_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $expiresAt = $otpRecord?->getAttribute('expires_at');

            if (
                ! $otpRecord
                || ! $expiresAt instanceof CarbonInterface
                || $expiresAt->isPast()
                || $otpRecord->attempt_count >= self::MAX_ATTEMPTS
            ) {
                return null;
            }

            $currentAttempts = (int) $otpRecord->attempt_count;

            if (! Hash::check($otp, $otpRecord->otp_hash)) {
                $attempts = $currentAttempts + 1;
                $payload = ['attempt_count' => $attempts];

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $payload['expires_at'] = now();
                }

                WhatsappOtp::query()
                    ->whereKey($otpRecord->getKey())
                    ->whereNull('verified_at')
                    ->where('attempt_count', $currentAttempts)
                    ->update($payload);

                return null;
            }

            $consumed = WhatsappOtp::query()
                ->whereKey($otpRecord->getKey())
                ->whereNull('verified_at')
                ->where('attempt_count', $currentAttempts)
                ->update(['verified_at' => now()]);

            if ($consumed !== 1) {
                return null;
            }

            return $otpRecord->fresh();
        });
    }

    /**
     * Kirim kode lewat WagHub dengan purpose OTP (wajib menyertakan expires_at).
     */
    private function send(WhatsappOtp $otpRecord, string $otp, int $expiresIn): void
    {
        $validFor = $expiresIn % 60 === 0 ? intdiv($expiresIn, 60).' menit' : $expiresIn.' detik';
        $key = 'lms-login-'.$otpRecord->id;

        $response = $this->waghub->send([
            'recipient' => ['type' => 'phone', 'value' => $otpRecord->whatsapp_number],
            'message' => [
                'type' => 'text',
                'text' => 'Kode OTP '.config('app.name').' Anda: '.$otp.'. Berlaku '.$validFor.'. Jangan bagikan kode ini kepada siapa pun.',
            ],
            'purpose' => config('services.waghub.otp_purpose'),
            'mode' => config('services.waghub.mode'),
            'route_key' => config('services.waghub.route_key'),
            'expires_at' => $otpRecord->expires_at->toIso8601String(),
            'client_reference' => $key,
        ], $key);

        if (! $response->successful()) {
            throw new RuntimeException('WagHub menolak pesan (HTTP '.$response->status().').');
        }
    }
}
