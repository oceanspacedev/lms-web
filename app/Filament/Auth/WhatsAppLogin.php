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
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\RawJs;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Halaman login alternatif: nomor WhatsApp, lalu kode OTP.
 */
class WhatsAppLogin extends SimplePage
{
    use WithRateLimiting;

    public const PHONE_FIELD_ID = 'whatsapp-login-phone';

    public const CODE_FIELD_ID = 'whatsapp-login-code';

    /** Event browser yang menyelaraskan hitung mundur kirim ulang dengan sisa waktu dari server. */
    public const RESEND_COUNTDOWN_EVENT = 'whatsapp-resend-countdown';

    /**
     * Hitung mundur berjalan di browser (Alpine) tanpa polling ke server, dimulai dari sisa detik yang dihitung server.
     * Saat Livewire merender ulang, Alpine memakai sisa detik terbaru dari server; batas kirim ulang tetap diputuskan server.
     */
    private const RESEND_COUNTDOWN_DATA = <<<'JS'
    {
        endsAt: Date.now() + %d * 1000,
        now: Date.now(),
        timer: null,
        get secondsLeft() { return Math.max(0, Math.ceil((this.endsAt - this.now) / 1000)) },
        init() { this.timer = setInterval(() => this.now = Date.now(), 1000) },
        destroy() { clearInterval(this.timer) },
    }
    JS;

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
        $this->focusField(self::CODE_FIELD_ID);
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
            ->label(fn (): Htmlable => $this->resendCodeLabel())
            ->link()
            ->extraAttributes(fn (): array => $this->resendCountdownAttributes())
            ->action(function (): void {
                try {
                    app(WhatsAppLoginService::class)->requestCode((string) $this->phone, request()->ip());
                    Notification::make()->success()->title('Kode baru dikirim')->body('Kode sebelumnya tidak berlaku lagi.')->send();
                    $this->focusField(self::CODE_FIELD_ID);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();
                }

                $this->dispatch(self::RESEND_COUNTDOWN_EVENT, seconds: $this->secondsUntilResend());
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
                $this->focusField(self::PHONE_FIELD_ID);
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
                ->mask(RawJs::make(WaghubService::PHONE_INPUT_MASK))->extraInputAttributes(['inputmode' => 'tel'])
                ->placeholder('081234567890')
                ->maxLength(30)
                ->required()
                ->id(self::PHONE_FIELD_ID)
                ->autofocus()
                ->autocomplete('tel')
                ->visible(fn (): bool => $this->step === 'phone'),
            TextInput::make('code')
                ->label('Kode dari WhatsApp')
                ->placeholder(str_repeat('•', WhatsAppLoginService::CODE_LENGTH))
                ->mask(str_repeat('9', WhatsAppLoginService::CODE_LENGTH))
                ->length(WhatsAppLoginService::CODE_LENGTH)
                ->regex('/^[0-9]+$/')
                ->validationMessages([
                    'regex' => 'Kode hanya berisi angka.',
                    'size' => 'Kode terdiri dari '.WhatsAppLoginService::CODE_LENGTH.' angka.',
                ])
                ->required()
                ->id(self::CODE_FIELD_ID)
                ->autofocus()
                ->autocomplete('one-time-code')
                ->extraInputAttributes(['inputmode' => 'numeric', 'pattern' => '[0-9]*', 'style' => 'letter-spacing:.4em;font-size:1.25rem;text-align:center'])
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
                            ->icon(fn (): ?Htmlable => $this->step === 'phone' ? new HtmlString(Blade::render('<x-whatsapp-icon :size="20" />')) : null)
                            ->submit('submit'),
                    ])->fullWidth(),
                    Text::make('Tidak menerima kode? Pastikan nomor sama dengan nomor di profil Anda, tunggu beberapa saat, lalu kirim ulang atau masuk dengan email dan password.')
                        ->size(TextSize::Small)
                        ->visible(fn (): bool => $this->step === 'code'),
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

    /**
     * Fokuskan input setelah Livewire selesai merender langkah baru (atribut autofocus tidak menyala ulang saat render ulang).
     */
    private function focusField(string $fieldId): void
    {
        $this->js("document.getElementById('{$fieldId}')?.focus()");
    }

    private function secondsUntilResend(): int
    {
        return app(WhatsAppLoginService::class)->secondsUntilResend((string) $this->phone);
    }

    private function resendCodeLabel(): Htmlable
    {
        $seconds = $this->secondsUntilResend();

        return new HtmlString('Kirim ulang kode <span x-show="secondsLeft > 0" x-text="`(${secondsLeft})`"'.($seconds > 0 ? '' : ' style="display: none"').'>('.$seconds.')</span>');
    }

    /**
     * Atribut Alpine untuk tombol kirim ulang: nonaktif selama hitung mundur masih berjalan.
     *
     * @return array<string, string|bool>
     */
    private function resendCountdownAttributes(): array
    {
        $seconds = $this->secondsUntilResend();

        return [
            'x-data' => sprintf(self::RESEND_COUNTDOWN_DATA, $seconds),
            'x-on:'.self::RESEND_COUNTDOWN_EVENT.'.window' => 'endsAt = Date.now() + $event.detail.seconds * 1000; now = Date.now()',
            'x-bind:disabled' => 'secondsLeft > 0',
            'disabled' => $seconds > 0,
        ];
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
