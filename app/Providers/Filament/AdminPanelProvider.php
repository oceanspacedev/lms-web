<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\WhatsAppLogin;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\Pages\Login;
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
            ->path('admin')
            ->login()
            ->profile(EditProfile::class)
            ->routes(fn () => Route::get('/login/whatsapp', WhatsAppLogin::class)->name('auth.whatsapp-login'))
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): View => view('filament.auth.whatsapp-login-link'),
                scopes: Login::class,
            )
            ->renderHook(
                PanelsRenderHook::PAGE_HEADER_ACTIONS_AFTER,
                fn (): View => view('filament.resources.documents.filters'),
                scopes: ListDocuments::class,
            )
            ->colors([
                'primary' => Color::Amber,
            ])
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
}
