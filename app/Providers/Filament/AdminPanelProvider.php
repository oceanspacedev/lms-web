<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\WhatsAppLogin;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use Apriansyahrs\MekayaTheme\Auth\MekayaLogin;
use Apriansyahrs\MekayaTheme\MekayaPlugin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->brandName('LMS Legal')
            ->path('admin')
            // Tema Mekaya (sama dengan member-web): sidebar, topbar, kartu, tabel yang ringkas.
            // Pengaturan auth & tema di bawah dipanggil setelah plugin supaya menimpa bawaan Mekaya.
            ->plugin(
                MekayaPlugin::make()
                    ->colors(['primary' => Color::Blue])
                    ->sidebarWidth('16rem'),
            )
            ->viteTheme('resources/css/app.css')
            ->login(MekayaLogin::class)
            ->registration(null)
            ->passwordReset(null)
            ->profile(EditProfile::class, isSimple: false)
            ->routes(fn () => Route::get('/login/whatsapp', WhatsAppLogin::class)->name('auth.whatsapp-login'))
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): View => view('filament.auth.whatsapp-login-link'),
                scopes: MekayaLogin::class,
            )
            ->renderHook(
                PanelsRenderHook::PAGE_HEADER_ACTIONS_AFTER,
                fn (): View => view('filament.resources.documents.filters'),
                scopes: ListDocuments::class,
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups([
                'Data Master',
                'Administrasi',
            ])
            ->navigationItems([
                NavigationItem::make('Horizon')
                    ->group('Administrasi')
                    ->sort(2)
                    ->icon(Heroicon::OutlinedQueueList)
                    ->url(fn (): string => route('horizon.index'), shouldOpenInNewTab: true)
                    ->visible(fn (): bool => Gate::allows('viewHorizon')),
                NavigationItem::make('Log Laravel')
                    ->group('Administrasi')
                    ->sort(3)
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (): string => route('log-viewer.index'), shouldOpenInNewTab: true)
                    ->visible(fn (): bool => config('log-viewer.enabled') && Gate::allows('viewLogViewer')),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup('Data Master')
                    ->navigationLabel('Hak Akses')
                    ->navigationSort(4),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Palet monokrom aplikasi (sama dengan member-web): tombol & menu aktif hitam arang
     * (shade 600/700 = #27272A/#18181B), shade terang abu hangat untuk latar sorotan.
     * Color::hex() tidak dipakai karena memakai tangga terang tetap yang membuat warna gelap jadi pucat.
     *
     * @return array<int, string>
     */
    private static function charcoal(): array
    {
        return array_map(Color::convertToOklch(...), [
            50 => '#FAFAF9',
            100 => '#F4F4F2',
            200 => '#E7E5E4',
            300 => '#D6D3D1',
            400 => '#A8A29E',
            500 => '#6B6B73',
            600 => '#27272A',
            700 => '#18181B',
            800 => '#141416',
            900 => '#0E0E10',
            950 => '#09090B',
        ]);
    }
}
