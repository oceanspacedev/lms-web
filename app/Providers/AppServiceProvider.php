<?php

namespace App\Providers;

use App\Models\User;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('public-request-create', fn (Request $request): Limit => Limit::perMinutes(10, 5)->by($request->ip()));
        RateLimiter::for('public-request-revise', fn (Request $request): Limit => Limit::perMinutes(10, 5)->by($request->ip().'|'.$request->route('token')));
        RateLimiter::for('public-request-status', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip().'|'.$request->route('token')));

        FilamentAsset::register([
            Css::make('documents-browser', resource_path('css/documents-browser.css'))
                ->relativePublicPath('css/filament/documents-browser.css'),
        ]);

        if (PHP_OS_FAMILY === 'Windows' && $this->app->runningInConsole()) {
            ServeCommand::$passthroughVariables = array_values(array_unique([
                ...ServeCommand::$passthroughVariables,
                'TEMP',
                'TMP',
                'TMPDIR',
            ]));
        }

        Gate::define('viewLogViewer', function (?User $user): bool {
            return $user?->can('View:LogViewer') ?? false;
        });
    }
}
