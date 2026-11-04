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
    /** @var array<string, string> Tahap yang dipantau staf legal (nilai filter `stage`), beserta labelnya di kartu. */
    public const STAGES = ['to_review' => 'Menunggu Pemeriksaan', 'handover_on_time' => 'Menunggu Fisik', 'late' => 'Terlambat'];

    protected static ?int $sort = 1;

    protected ?string $heading = 'Pengajuan Menunggu Tindakan';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', DocumentRequest::class)
            && (static::visibleRequests()->whereIn('status', DocumentRequest::WAITING_STATUSES)->exists()
                || static::visibleRequests()->handoverOnTime()->exists()
                || static::visibleRequests()->handoverLate()->exists());
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

        $waiting = static::visibleRequests()->whereIn('status', DocumentRequest::WAITING_STATUSES)->count();
        $waitingLong = static::visibleRequests()->waitingLongerThan($days)->count();
        $late = static::visibleRequests()->handoverLate()->count();
        $lateWithoutReason = static::visibleRequests()->lateWithoutReason()->count();
        $stageUrl = fn (string $stage, array $extra = []): string => DocumentRequestResource::getUrl('index', ['filters' => ['stage' => ['value' => $stage], ...$extra]]);

        return [
            Stat::make(self::STAGES['to_review'], $waiting)
                ->description($waitingLong > 0 ? "{$waitingLong} menunggu lebih dari {$days} hari" : "Tidak ada yang menunggu lebih dari {$days} hari")
                ->color($waitingLong > 0 ? 'warning' : 'gray')
                ->url($stageUrl('to_review', $waitingLong > 0 ? ['waiting_long' => ['isActive' => true]] : [])),
            Stat::make(self::STAGES['handover_on_time'], static::visibleRequests()->handoverOnTime()->count())
                ->description('Dokumen fisik belum diterima, belum lewat 7 hari')
                ->color('gray')
                ->url($stageUrl('handover_on_time')),
            Stat::make(self::STAGES['late'], $late)
                ->description("{$lateWithoutReason} belum ada alasan")
                ->color($late > 0 ? 'danger' : 'gray')
                ->url($stageUrl('late')),
        ];
    }
}
