<?php

namespace App\Services;

use App\Mail\EmailLoginMail;
use App\Models\EmailOtp;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Kode OTP dan magic link untuk login email. Satu record menyimpan keduanya, sehingga memakai salah satunya
 * menghabiskan yang lain.
 */
class EmailOtpService
{
    public const MAX_ATTEMPTS = 5;

    public const TOKEN_LENGTH = 64;

    /**
     * Login email hanya ditawarkan bila mailer default benar-benar mengirim email (bukan log/array).
     */
    public static function isConfigured(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport");

        return ! in_array($transport, ['', 'log', 'array'], true)
            && filled(config('mail.from.address'));
    }

    /**
     * @return array{otp_record: EmailOtp, expires_in: int}
     */
    public function issue(User $user, string $email, string $purpose): array
    {
        $expiresIn = max(60, (int) config('services.email_login.expires_in', 600));
        $lockKey = 'email-otp:issue:'.hash('sha256', implode('|', [
            (string) $user->getKey(),
            $email,
            $purpose,
        ]));

        return Cache::lock($lockKey, 90)->block(10, function () use (
            $user,
            $email,
            $purpose,
            $expiresIn,
        ): array {
            $expiresAt = now()->addSeconds($expiresIn);
            $otp = (string) random_int(100000, 999999);
            $token = Str::random(self::TOKEN_LENGTH);

            $otpRecord = DB::transaction(function () use (
                $user,
                $email,
                $purpose,
                $otp,
                $token,
                $expiresAt,
            ): EmailOtp {
                EmailOtp::query()
                    ->where('email', $email)
                    ->where('purpose', $purpose)
                    ->whereNull('verified_at')
                    ->update(['expires_at' => now()]);

                return EmailOtp::query()->create([
                    'user_id' => $user->id,
                    'email' => $email,
                    'purpose' => $purpose,
                    'otp_hash' => Hash::make($otp),
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => $expiresAt,
                    'attempt_count' => 0,
                ]);
            });

            try {
                Mail::to($email)->send(new EmailLoginMail(
                    name: (string) $user->name,
                    otp: $otp,
                    loginUrl: route('email-login.link', ['token' => $token]),
                    expiresInMinutes: intdiv($expiresIn, 60),
                ));
            } catch (Throwable $exception) {
                $otpRecord->forceFill(['expires_at' => now()])->save();

                Log::warning('Gagal mengirim OTP email', [
                    'user_id' => $user->id,
                    'purpose' => $purpose,
                    'email_domain' => Str::after($email, '@'),
                    'exception' => $exception::class,
                ]);

                throw new RuntimeException('Gagal mengirim OTP email.', previous: $exception);
            }

            return [
                'otp_record' => $otpRecord,
                'expires_in' => $expiresIn,
            ];
        });
    }

    public function verify(User $user, string $email, string $purpose, string $otp): ?EmailOtp
    {
        return DB::transaction(function () use ($user, $email, $purpose, $otp): ?EmailOtp {
            $otpRecord = EmailOtp::query()
                ->where('email', $email)
                ->where('purpose', $purpose)
                ->where('user_id', $user->id)
                ->whereNull('verified_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otpRecord || ! $this->isUsable($otpRecord)) {
                return null;
            }

            $currentAttempts = (int) $otpRecord->attempt_count;

            if (! Hash::check($otp, $otpRecord->otp_hash)) {
                $attempts = $currentAttempts + 1;
                $payload = ['attempt_count' => $attempts];

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $payload['expires_at'] = now();
                }

                EmailOtp::query()
                    ->whereKey($otpRecord->getKey())
                    ->whereNull('verified_at')
                    ->where('attempt_count', $currentAttempts)
                    ->update($payload);

                return null;
            }

            return $this->consume($otpRecord) ? $otpRecord->fresh() : null;
        });
    }

    /**
     * Menukar token magic link dengan user pemiliknya. User dikunci sebelum record OTP, urutan yang sama dengan
     * alur verifikasi kode, agar keduanya tidak saling deadlock.
     */
    public function consumeMagicLink(string $token, string $purpose): ?User
    {
        if (strlen($token) !== self::TOKEN_LENGTH) {
            return null;
        }

        $candidate = EmailOtp::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('purpose', $purpose)
            ->first(['id', 'user_id']);

        if (! $candidate?->user_id) {
            return null;
        }

        return DB::transaction(function () use ($candidate): ?User {
            $user = User::query()
                ->whereKey($candidate->user_id)
                ->lockForUpdate()
                ->first();

            $otpRecord = EmailOtp::query()
                ->whereKey($candidate->getKey())
                ->whereNull('verified_at')
                ->lockForUpdate()
                ->first();

            if (
                ! $user
                || ! $otpRecord
                || ! $this->isUsable($otpRecord)
                || Str::lower(trim((string) $user->email)) !== $otpRecord->email
            ) {
                return null;
            }

            return $this->consume($otpRecord) ? $user : null;
        });
    }

    private function isUsable(EmailOtp $otpRecord): bool
    {
        $expiresAt = $otpRecord->getAttribute('expires_at');

        return $expiresAt instanceof CarbonInterface
            && ! $expiresAt->isPast()
            && $otpRecord->attempt_count < self::MAX_ATTEMPTS;
    }

    private function consume(EmailOtp $otpRecord): bool
    {
        return EmailOtp::query()
            ->whereKey($otpRecord->getKey())
            ->whereNull('verified_at')
            ->where('attempt_count', (int) $otpRecord->attempt_count)
            ->update(['verified_at' => now()]) === 1;
    }
}
