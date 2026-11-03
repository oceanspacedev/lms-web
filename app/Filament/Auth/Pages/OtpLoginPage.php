<?php

namespace App\Filament\Auth\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsIconAlias;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Kerangka bersama halaman login tanpa password (WhatsApp dan email): kirim kode, lalu verifikasi kode.
 *
 * @property-read Action $loginAction
 * @property-read Schema $form
 */
abstract class OtpLoginPage extends SimplePage
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public bool $awaitingOtp = false;

    protected Width|string|null $maxWidth = 'full';

    /**
     * Halaman login OTP berada di luar route panel, jadi tidak ada middleware panel yang menyiapkan panel admin, baik
     * pada request pertama maupun request Livewire berikutnya (send, verify, change*). Tanpa panel aktif, form yang
     * dirender ulang memakai warna bawaan Filament (tombol "Verifikasi dan Masuk" tampak pucat/nonaktif), sehingga
     * panel disiapkan di setiap request, bukan hanya di mount().
     */
    public function boot(): void
    {
        if (Filament::getCurrentPanel() === null) {
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Filament::bootCurrentPanel();
        }
    }

    abstract public function getHeaderIcon(): Heroicon;

    /**
     * Method Livewire yang dipanggil otomatis lewat wire:init setelah halaman tampil di browser.
     */
    public function getInitAction(): ?string
    {
        return null;
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function loginAction(): Action
    {
        return Action::make('login')
            ->link()
            ->label('Kembali ke halaman masuk')
            ->icon(match (__('filament-panels::layout.direction')) {
                'rtl' => FilamentIcon::resolve(PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN_RTL) ?? Heroicon::ArrowRight,
                default => FilamentIcon::resolve(PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN) ?? Heroicon::ArrowLeft,
            })
            ->url(filament()->getLoginUrl());
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSubmitFormAction(),
        ];
    }

    abstract protected function getSubmitFormAction(): Action;

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('submit')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->alignment($this->getFormActionsAlignment())
                        ->fullWidth($this->hasFullWidthFormActions())
                        ->key('form-actions'),
                ]),
        ]);
    }

    public function getView(): string
    {
        return 'filament.auth.otp-login';
    }

    public function hasLogo(): bool
    {
        return false;
    }

    protected function canLoginToPanel(User $user): bool
    {
        return $user->canAccessPanel(Filament::getCurrentPanel() ?? Filament::getPanel('admin'));
    }

    protected function completeLogin(User $user): void
    {
        Filament::auth()->login($user);
        session()->regenerate();

        $this->redirectIntended(Filament::getUrl());
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: int}>  $limits
     */
    protected function consumeRateLimits(array $limits, string $field, string $message): void
    {
        $limited = false;

        foreach ($limits as [$key, $maxAttempts, $decaySeconds]) {
            if (RateLimiter::hit($key, $decaySeconds) > $maxAttempts) {
                $limited = true;
            }
        }

        if ($limited) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
