<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Models\DocumentRequest;
use App\Models\ReminderTemplate;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class WaitingRequestsOverview extends StatsOverviewWidget
{
    /** @var array<string, string> Status yang menunggu staf, beserta labelnya di kartu. */
    public const STAGES = ['submitted' => 'Menunggu Pemeriksaan', 'review' => 'Menunggu Persetujuan'];

    protected static ?int $sort = 1;

    protected ?string $heading = 'Pengajuan Menunggu Tindakan';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', DocumentRequest::class)
            && static::visibleRequests()->whereIn('status', DocumentRequest::WAITING_STATUSES)->exists();
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    /**
     * Pengganti pemeriksaan bawaan Filament pada setiap poll: canView() ikut bergantung pada ada-tidaknya pengajuan menunggu,
     * jadi setelah pengajuan terakhir diproses poll berikutnya akan gagal 403. Saat poll cukup diperiksa izinnya saja.
     */
    public function hydrateCanAuthorizeAccess(): void
    {
        abort_unless(Gate::allows('viewAny', DocumentRequest::class), 403);
    }

    /** @return Builder<DocumentRequest> Pengajuan yang boleh dilihat user saat ini. */
    private static function visibleRequests(): Builder
    {
        return DocumentRequestResource::getEloquentQuery();
    }

    /** Izin sudah diperiksa saat mount dan setiap poll; angka boleh nol bila semua pengajuan sudah diproses sejak halaman dibuka. */
    protected function getStats(): array
    {
        $days = ReminderTemplate::requestReminderSettings()['after_days'];

        return collect(self::STAGES)->map(function (string $label, string $status) use ($days): Stat {
            $total = static::visibleRequests()->where('status', $status)->count();
            $waiting = static::visibleRequests()->where('status', $status)->waitingLongerThan($days)->count();

            return Stat::make($label, $total)
                ->description($waiting > 0 ? "{$waiting} menunggu lebih dari {$days} hari" : "Tidak ada yang menunggu lebih dari {$days} hari")
                ->color($waiting > 0 ? 'warning' : 'gray')
                ->url(DocumentRequestResource::getUrl('index', ['filters' => [
                    'status' => ['value' => $status],
                    ...($waiting > 0 ? ['waiting_long' => ['isActive' => true]] : []),
                ]]));
        })->values()->all();
    }
}
