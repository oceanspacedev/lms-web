<?php

namespace App\Filament\Auth\Pages;

use App\Filament\Auth\Concerns\InteractsWithWhatsAppLogin;
use App\Models\User;
use App\Models\WhatsappOtp;
use App\Services\WaghubService;
use App\Services\WhatsAppOtpService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PhoneLogin extends OtpLoginPage
{
    use InteractsWithWhatsAppLogin;

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        if (! app(WaghubService::class)->isConfigured()) {
            $this->redirect(Filament::getLoginUrl());

            return;
        }

        $this->form->fill();
    }

    public function send(WhatsAppOtpService $otpService): void
    {
        $data = $this->form->getState();
        $number = $this->normalizeWhatsAppNumber($data['whatsapp_number'] ?? null);

        if (! $number) {
            throw ValidationException::withMessages([
                'data.whatsapp_number' => 'Nomor WhatsApp tidak valid.',
            ]);
        }

        $this->throttleOtpSend($number);

        $user = $this->findEligibleWebUserByWhatsApp($number);

        if (! $user) {
            throw ValidationException::withMessages([
                'data.whatsapp_number' => $this->unavailableWhatsAppMessage(),
            ]);
        }

        try {
            $otpService->issue($user, $number, WhatsappOtp::PURPOSE_LOGIN);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'data.whatsapp_number' => 'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.',
            ]);
        }

        $this->awaitingOtp = true;
        $this->form->fill([
            'whatsapp_number' => $number,
            'otp' => null,
        ]);
    }

    public function verify(WhatsAppOtpService $otpService): void
    {
        $data = $this->form->getState();
        $number = $this->normalizeWhatsAppNumber($data['whatsapp_number'] ?? null);

        if ($number) {
            $this->throttleOtpVerification($number);
        }

        $user = $number ? $this->findEligibleWebUserByWhatsApp($number) : null;

        if (! $user) {
            throw ValidationException::withMessages([
                'data.whatsapp_number' => $this->unavailableWhatsAppMessage(),
            ]);
        }

        $verifiedUser = DB::transaction(function () use ($otpService, $user, $number, $data): ?User {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedUser || ! $this->userMatchesWhatsAppNumber($lockedUser, $number)) {
                return null;
            }

            $otpRecord = $otpService->verify(
                $lockedUser,
                $number,
                WhatsappOtp::PURPOSE_LOGIN,
                (string) ($data['otp'] ?? ''),
            );

            return $otpRecord ? $lockedUser : null;
        });

        if (! $verifiedUser) {
            throw ValidationException::withMessages([
                'data.otp' => 'OTP tidak valid atau sudah kedaluwarsa.',
            ]);
        }

        $this->completeLogin($verifiedUser);
    }

    public function submit(WhatsAppOtpService $otpService): void
    {
        if ($this->awaitingOtp) {
            $this->verify($otpService);

            return;
        }

        $this->send($otpService);
    }

    public function changePhone(): void
    {
        $this->awaitingOtp = false;
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('whatsapp_number')
                ->label('Nomor WhatsApp')
                ->tel()
                ->mask(RawJs::make(WaghubService::PHONE_INPUT_MASK))
                ->extraInputAttributes(['inputmode' => 'tel'])
                ->placeholder('081234567890')
                ->required()
                ->maxLength(30)
                ->autocomplete('tel')
                ->disabled(fn (): bool => $this->awaitingOtp)
                ->dehydrated()
                ->autofocus(),
            TextInput::make('otp')
                ->label('Kode OTP')
                ->helperText('Masukkan 6 digit kode yang dikirim ke WhatsApp.')
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
        return 'Masuk dengan WhatsApp';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Masuk dengan WhatsApp';
    }

    public function getHeaderIcon(): Heroicon
    {
        return Heroicon::DevicePhoneMobile;
    }

    protected function getSubmitFormAction(): Action
    {
        return Action::make('submit')
            ->label(fn (): string => $this->awaitingOtp ? 'Verifikasi dan Masuk' : 'Kirim OTP WhatsApp')
            ->submit('submit');
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->awaitingOtp) {
            return Action::make('changePhone')
                ->link()
                ->label('Ganti nomor atau kirim ulang OTP')
                ->action('changePhone');
        }

        if (! filament()->hasLogin()) {
            return null;
        }

        return $this->loginAction;
    }

    protected function throttleOtpSend(string $number): void
    {
        $numberKey = hash('sha256', $number);
        $ipKey = hash('sha256', (string) request()->ip());

        $this->consumeRateLimits([
            ["whatsapp-otp:filament:number:5m:{$numberKey}", 3, 300],
            ["whatsapp-otp:filament:number:1h:{$numberKey}", 10, 3600],
            ["whatsapp-otp:filament:ip:1m:{$ipKey}", 20, 60],
        ], 'data.whatsapp_number', 'Terlalu banyak permintaan OTP. Coba lagi sebentar lagi.');
    }

    protected function throttleOtpVerification(string $number): void
    {
        $numberKey = hash('sha256', $number);
        $ipKey = hash('sha256', (string) request()->ip());

        $this->consumeRateLimits([
            ["whatsapp-verify:filament:number:5m:{$numberKey}", 15, 300],
            ["whatsapp-verify:filament:ip:1m:{$ipKey}", 20, 60],
        ], 'data.otp', 'Terlalu banyak percobaan OTP. Coba lagi sebentar lagi.');
    }
}
