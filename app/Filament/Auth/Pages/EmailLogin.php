<?php

namespace App\Filament\Auth\Pages;

use App\Filament\Auth\Concerns\InteractsWithEmailLogin;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\EmailOtpService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

class EmailLogin extends OtpLoginPage
{
    use InteractsWithEmailLogin;

    /**
     * Token magic link dari URL. Token baru ditukar lewat wire:init (request Livewire setelah halaman tampil), bukan
     * saat GET, agar pemindai link di email yang hanya membuka URL tidak menghabiskan link sebelum user mengkliknya.
     */
    #[Locked]
    public ?string $magicToken = null;

    public function mount(?string $token = null): void
    {
        if (Filament::auth()->check()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        if (! EmailOtpService::isConfigured()) {
            $this->redirect(Filament::getLoginUrl());

            return;
        }

        $this->magicToken = filled($token) ? $token : null;
        $this->form->fill();
    }

    public function getInitAction(): ?string
    {
        return filled($this->magicToken) ? 'consumeMagicLink' : null;
    }

    public function consumeMagicLink(EmailOtpService $otpService): void
    {
        $token = (string) $this->magicToken;
        $this->magicToken = null;

        if ($token === '') {
            return;
        }

        $ipKey = hash('sha256', (string) request()->ip());

        $this->consumeRateLimits([
            ["email-link:filament:ip:1m:{$ipKey}", 20, 60],
        ], 'data.email', 'Terlalu banyak percobaan masuk. Coba lagi sebentar lagi.');

        $user = $otpService->consumeMagicLink($token, EmailOtp::PURPOSE_LOGIN);

        if (! $user || ! $user->isActive() || ! $this->canLoginToPanel($user)) {
            throw ValidationException::withMessages([
                'data.email' => 'Link masuk tidak valid atau sudah kedaluwarsa. Minta kode baru di bawah.',
            ]);
        }

        $this->completeLogin($user);
    }

    public function send(EmailOtpService $otpService): void
    {
        $data = $this->form->getState();
        $email = $this->normalizeEmail($data['email'] ?? null);

        if (! $email) {
            throw ValidationException::withMessages([
                'data.email' => 'Alamat email tidak valid.',
            ]);
        }

        $this->throttleOtpSend($email);

        $user = $this->findEligibleWebUserByEmail($email);

        if (! $user) {
            throw ValidationException::withMessages([
                'data.email' => $this->unavailableEmailMessage(),
            ]);
        }

        try {
            $otpService->issue($user, $email, EmailOtp::PURPOSE_LOGIN);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'data.email' => 'Email belum bisa dikirim. Coba lagi sebentar lagi.',
            ]);
        }

        $this->awaitingOtp = true;
        $this->form->fill([
            'email' => $email,
            'otp' => null,
        ]);
    }

    public function verify(EmailOtpService $otpService): void
    {
        $data = $this->form->getState();
        $email = $this->normalizeEmail($data['email'] ?? null);

        if ($email) {
            $this->throttleOtpVerification($email);
        }

        $user = $email ? $this->findEligibleWebUserByEmail($email) : null;

        if (! $user) {
            throw ValidationException::withMessages([
                'data.email' => $this->unavailableEmailMessage(),
            ]);
        }

        $verifiedUser = DB::transaction(function () use ($otpService, $user, $email, $data): ?User {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedUser || ! $this->userMatchesEmail($lockedUser, $email)) {
                return null;
            }

            $otpRecord = $otpService->verify(
                $lockedUser,
                $email,
                EmailOtp::PURPOSE_LOGIN,
                (string) ($data['otp'] ?? ''),
            );

            return $otpRecord ? $lockedUser : null;
        });

        if (! $verifiedUser) {
            throw ValidationException::withMessages([
                'data.otp' => 'Kode tidak valid atau sudah kedaluwarsa.',
            ]);
        }

        $this->completeLogin($verifiedUser);
    }

    public function submit(EmailOtpService $otpService): void
    {
        if ($this->awaitingOtp) {
            $this->verify($otpService);

            return;
        }

        $this->send($otpService);
    }

    public function changeEmail(): void
    {
        $this->awaitingOtp = false;
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255)
                ->autocomplete('email')
                ->disabled(fn (): bool => $this->awaitingOtp)
                ->dehydrated()
                ->autofocus(),
            TextInput::make('otp')
                ->label('Kode OTP')
                ->helperText('Masukkan 6 digit kode dari email, atau klik tombol masuk di email tersebut.')
                ->required()
                ->numeric()
                ->length(6)
                ->autocomplete('one-time-code')
                ->autofocus()
                ->visible(fn (): bool => $this->awaitingOtp),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Masuk dengan Email';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Masuk dengan Email';
    }

    public function getHeaderIcon(): Heroicon
    {
        return Heroicon::Envelope;
    }

    protected function getSubmitFormAction(): Action
    {
        return Action::make('submit')
            ->label(fn (): string => $this->awaitingOtp ? 'Verifikasi dan Masuk' : 'Kirim Kode ke Email')
            ->submit('submit');
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (filled($this->magicToken)) {
            return 'Memproses link masuk…';
        }

        if ($this->awaitingOtp) {
            return Action::make('changeEmail')
                ->link()
                ->label('Ganti email atau kirim ulang kode')
                ->action('changeEmail');
        }

        if (! filament()->hasLogin()) {
            return null;
        }

        return $this->loginAction;
    }

    protected function throttleOtpSend(string $email): void
    {
        $emailKey = hash('sha256', $email);
        $ipKey = hash('sha256', (string) request()->ip());

        $this->consumeRateLimits([
            ["email-otp:filament:email:5m:{$emailKey}", 3, 300],
            ["email-otp:filament:email:1h:{$emailKey}", 10, 3600],
            ["email-otp:filament:ip:1m:{$ipKey}", 20, 60],
        ], 'data.email', 'Terlalu banyak permintaan kode. Coba lagi sebentar lagi.');
    }

    protected function throttleOtpVerification(string $email): void
    {
        $emailKey = hash('sha256', $email);
        $ipKey = hash('sha256', (string) request()->ip());

        $this->consumeRateLimits([
            ["email-verify:filament:email:5m:{$emailKey}", 15, 300],
            ["email-verify:filament:ip:1m:{$ipKey}", 20, 60],
        ], 'data.otp', 'Terlalu banyak percobaan kode. Coba lagi sebentar lagi.');
    }
}
