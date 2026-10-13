<?php

namespace App\Filament\Auth;

use App\Services\WaghubService;
use App\Services\WhatsAppLoginService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Halaman login alternatif: nomor WhatsApp, lalu kode OTP.
 */
class WhatsAppLogin extends SimplePage
{
    use WithRateLimiting;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    #[Locked]
    public string $step = 'phone';

    #[Locked]
    public ?string $phone = null;

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }

        $this->form->fill();
    }

    public function submit(): ?LoginResponse
    {
        if ($this->step === 'phone') {
            $this->sendCode();

            return null;
        }

        return $this->authenticate();
    }

    public function sendCode(): void
    {
        if (! $this->passesRateLimit()) {
            return;
        }

        $phone = (string) ($this->form->getState()['phone'] ?? '');
        $this->withFieldErrors('phone', fn () => app(WhatsAppLoginService::class)->requestCode($phone, request()->ip()));

        $this->phone = $phone;
        $this->step = 'code';
        $this->form->fill();
    }

    public function authenticate(): ?LoginResponse
    {
        if (! $this->passesRateLimit()) {
            return null;
        }

        $data = $this->form->getState();
        $user = $this->withFieldErrors('code', fn () => app(WhatsAppLoginService::class)->verifyCode((string) $this->phone, (string) $data['code']));

        Filament::auth()->login($user, (bool) ($data['remember'] ?? false));
        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function resendCodeAction(): Action
    {
        return Action::make('resendCode')
            ->label('Kirim ulang kode')
            ->link()
            ->action(function (): void {
                try {
                    app(WhatsAppLoginService::class)->requestCode((string) $this->phone, request()->ip());
                    Notification::make()->success()->title('Kode baru dikirim')->body('Kode sebelumnya tidak berlaku lagi.')->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();
                }
            });
    }

    public function changeNumberAction(): Action
    {
        return Action::make('changeNumber')
            ->label('Ganti nomor')
            ->link()
            ->color('gray')
            ->action(function (): void {
                $this->step = 'phone';
                $this->phone = null;
                $this->form->fill();
            });
    }

    public function passwordLoginAction(): Action
    {
        return Action::make('passwordLogin')
            ->label('Masuk dengan email dan password')
            ->link()
            ->color('gray')
            ->url(filament()->getLoginUrl());
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('phone')
                ->label('Nomor WhatsApp')
                ->tel()
                ->placeholder('0812 3456 7890')
                ->maxLength(30)
                ->required()
                ->autofocus()
                ->autocomplete('tel')
                ->visible(fn (): bool => $this->step === 'phone'),
            TextInput::make('code')
                ->label('Kode dari WhatsApp')
                ->placeholder(str_repeat('•', WhatsAppLoginService::CODE_LENGTH))
                ->maxLength(WhatsAppLoginService::CODE_LENGTH + 2)
                ->required()
                ->autofocus()
                ->autocomplete('one-time-code')
                ->extraInputAttributes(['inputmode' => 'numeric', 'style' => 'letter-spacing:.4em;font-size:1.25rem;text-align:center'])
                ->helperText('Kode berlaku '.WhatsAppLoginService::TTL_MINUTES.' menit. Kirim ulang tersedia setelah '.WhatsAppLoginService::RESEND_SECONDS.' detik.')
                ->visible(fn (): bool => $this->step === 'code'),
            Checkbox::make('remember')
                ->label('Ingat saya')
                ->visible(fn (): bool => $this->step === 'code'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('submit')
                ->footer([
                    Actions::make([
                        Action::make('submit')
                            ->label(fn (): string => $this->step === 'phone' ? 'Kirim kode' : 'Masuk')
                            ->submit('submit'),
                    ])->fullWidth(),
                    Actions::make([$this->resendCodeAction(), $this->changeNumberAction()])
                        ->alignBetween()
                        ->visible(fn (): bool => $this->step === 'code'),
                    Actions::make([$this->passwordLoginAction()])->alignCenter(),
                ]),
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

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->step === 'code') {
            return 'Jika nomor '.$this->maskedPhone().' terdaftar, kode '.WhatsAppLoginService::CODE_LENGTH.' digit sudah dikirim ke WhatsApp.';
        }

        return 'Kami kirim kode ke nomor WhatsApp yang terdaftar di profil Anda.';
    }

    private function maskedPhone(): string
    {
        $phone = app(WaghubService::class)->normalizePhone($this->phone) ?? '';

        return strlen($phone) > 6 ? '0'.substr($phone, 2, 3).str_repeat('•', strlen($phone) - 7).substr($phone, -2) : '';
    }

    private function passesRateLimit(): bool
    {
        try {
            $this->rateLimit(10);

            return true;
        } catch (TooManyRequestsException $exception) {
            Notification::make()->danger()->title('Terlalu banyak percobaan')->body('Coba lagi dalam '.$exception->secondsUntilAvailable.' detik.')->send();

            return false;
        }
    }

    /**
     * Teruskan galat validasi service ke field form yang sesuai.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withFieldErrors(string $field, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['data.'.$field => collect($exception->errors())->flatten()->all()]);
        }
    }
}
