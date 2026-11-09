<?php

namespace App\Providers;

use App\Filament\Auth\Pages\EmailLogin;
use App\Filament\Auth\Pages\PhoneLogin;
use App\Http\Controllers\PublicDocumentRequestController;
use App\Models\User;
use App\Services\Trash\CompanyTrashHandler;
use App\Services\Trash\DocumentRequestTrashHandler;
use App\Services\Trash\DocumentTrashHandler;
use App\Services\Trash\DocumentTypeTrashHandler;
use App\Services\Trash\TrashHandler;
use App\Services\Trash\TrashManager;
use App\Services\Trash\UserTrashHandler;
use App\Services\WaghubService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag([CompanyTrashHandler::class, DocumentTypeTrashHandler::class, DocumentTrashHandler::class, DocumentRequestTrashHandler::class, UserTrashHandler::class], TrashHandler::class);
        $this->app->when(TrashManager::class)->needs('$handlers')->giveTagged(TrashHandler::class);
        $this->app->singleton(TrashManager::class);
    }

    /**
     * Layar Hak Akses (Shield) menampilkan "Nama Guard" berisi "web": istilah teknis yang tidak berarti bagi pengguna.
     * Aplikasi hanya memakai guard web, jadi kolom dan isiannya disembunyikan; isian tetap terkirim agar nilai bawaannya tersimpan.
     */
    private function hideGuardNameOnRoleScreens(): void
    {
        TextColumn::configureUsing(function (TextColumn $column): void {
            if ($column->getName() === 'guard_name') {
                $column->hidden();
            }
        });
        TextInput::configureUsing(function (TextInput $input): void {
            if ($input->getName() === 'guard_name') {
                $input->hidden()->dehydratedWhenHidden();
            }
        });
    }

    /**
     * Tanggal yang formatnya tidak ditulis langsung (mis. kolom "Terakhir Diubah" bawaan Shield) memakai format Indonesia
     * yang sama dengan halaman lain ("09 Nov 2026 10:36") dan jam WIB. Format/zona yang ditulis pada kolom tetap diutamakan.
     */
    private function useIndonesianDateDefaults(): void
    {
        Table::configureUsing(fn (Table $table): Table => $table->defaultDateDisplayFormat('d M Y')->defaultDateTimeDisplayFormat('d M Y H:i'));
        Schema::configureUsing(fn (Schema $schema): Schema => $schema->defaultDateDisplayFormat('d M Y')->defaultDateTimeDisplayFormat('d M Y H:i'));
        // Kalender Filament menampilkan tanggal terpilih sebagai "Nov 30, 2026" bila tidak diatur; samakan dengan "30 Nov 2026".
        DateTimePicker::configureUsing(fn (DateTimePicker $picker): DateTimePicker => $picker->defaultDateDisplayFormat('d M Y')->defaultDateTimeDisplayFormat('d M Y H:i')->defaultDateTimeWithSecondsDisplayFormat('d M Y H:i:s'));
        TextColumn::configureUsing(function (TextColumn $column): void {
            if (in_array($column->getName(), ['created_at', 'updated_at'], true)) {
                $column->timezone(config('lms.reminder_timezone'));
            }
        });
    }

    /**
     * Placeholder pilihan mengikuti labelnya ("Pilih badan usaha") alih-alih "Pilih salah satu opsi" yang sama di semua isian.
     * Placeholder yang ditulis langsung pada isian tetap dipakai karena dipasang setelah konfigurasi ini.
     */
    private function useLabelBasedSelectPlaceholders(): void
    {
        Select::configureUsing(fn (Select $select): Select => $select->placeholder(fn (Select $component): string => 'Pilih '.collect(explode(' ', strip_tags((string) $component->getLabel())))
            ->map(fn (string $word): string => mb_strtoupper($word) === $word ? $word : mb_strtolower($word))
            ->implode(' ')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->hideGuardNameOnRoleScreens();
        $this->useLabelBasedSelectPlaceholders();
        $this->useIndonesianDateDefaults();
        Livewire::component('phone-login', PhoneLogin::class);
        Livewire::component('email-login', EmailLogin::class);

        RateLimiter::for('public-request-create', fn (Request $request): Limit => Limit::perMinutes(10, 5)->by($request->ip()));
        RateLimiter::for('public-request-revise', fn (Request $request): Limit => Limit::perMinutes(10, 5)->by($request->ip().'|'.$request->route('token')));
        RateLimiter::for('public-request-status', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip().'|'.$request->route('token')));
        RateLimiter::for('public-request-lookup', function (Request $request): array {
            $phone = $request->input('requester_phone');
            $phone = app(WaghubService::class)->normalizePhone(is_string($phone) ? $phone : null) ?? 'invalid';
            $submissionId = $request->input('submission_id');
            $submissionId = is_string($submissionId) || is_int($submissionId) ? ltrim((string) preg_replace('/\D/', '', (string) $submissionId), '0') : '';
            $tooManyAttempts = fn (Request $request, array $headers): Response => response()
                ->view('requests.lookup', ['throttled' => true], 429, [...$headers, ...PublicDocumentRequestController::PRIVATE_HEADERS]);

            // Batas per nomor WhatsApp dan per nomor pengajuan mencegah menebak pengajuan orang lain dari banyak IP.
            // Batas per IP diletakkan terakhir supaya header X-RateLimit sama untuk setiap percobaan.
            return [
                Limit::perHour(10)->by('phone:'.$phone)->response($tooManyAttempts),
                Limit::perHour(10)->by('submission:'.$submissionId)->response($tooManyAttempts),
                Limit::perMinute(6)->by($request->ip())->response($tooManyAttempts),
            ];
        });

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
