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
    public const STAGES = ['to_review' => 'Menunggu Pemeriksaan', 'signing' => 'Menunggu Tanda Tangan', 'handover' => 'Menunggu Fisik', 'late' => 'Terlambat'];

    protected static ?int $sort = 1;

    protected ?string $heading = 'Pengajuan Menunggu Tindakan';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', DocumentRequest::class)
            && (static::visibleRequests()->whereIn('status', DocumentRequest::WAITING_STATUSES)->exists()
                || static::visibleRequests()->where('status', 'signing')->exists()
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
        $signing = static::visibleRequests()->where('status', 'signing')->count();
        $scanOverdue = static::visibleRequests()->scanOverdue()->count();
        $late = static::visibleRequests()->handoverLate()->count();
        $lateWithoutReason = static::visibleRequests()->lateWithoutReason()->count();
        $stageUrl = fn (string $stage, array $extra = []): string => DocumentRequestResource::getUrl('index', ['filters' => ['stage' => ['value' => $stage], ...$extra]]);

        return [
            Stat::make(self::STAGES['to_review'], $waiting)
                ->description($waitingLong > 0 ? "{$waitingLong} menunggu lebih dari {$days} hari" : "Tidak ada yang menunggu lebih dari {$days} hari")
                ->color($waitingLong > 0 ? 'warning' : 'gray')
                ->url($stageUrl('to_review', $waitingLong > 0 ? ['waiting_long' => ['isActive' => true]] : [])),
            Stat::make(self::STAGES['signing'], $signing)
                ->description($scanOverdue > 0 ? "{$scanOverdue} belum unggah scan lebih dari 7 hari" : 'Tidak ada scan yang tertunda lebih dari 7 hari')
                ->color($scanOverdue > 0 ? 'warning' : 'gray')
                ->url($stageUrl($scanOverdue > 0 ? 'scan_overdue' : 'signing')),
            // Semua yang fisiknya belum diterima, termasuk yang terlambat; kartu Terlambat merinci yang lewat batas.
            Stat::make(self::STAGES['handover'], static::visibleRequests()->where('status', 'handover')->count())
                ->description($late > 0 ? "{$late} di antaranya terlambat" : 'Belum ada yang lewat batas 7 hari')
                ->color($late > 0 ? 'warning' : 'gray')
                ->url($stageUrl('handover')),
            Stat::make(self::STAGES['late'], $late)
                ->description(match (true) {
                    $late === 0 => 'Tidak ada yang terlambat',
                    $lateWithoutReason > 0 => "{$lateWithoutReason} belum ada alasan",
                    default => 'Semua sudah memberi alasan',
                })
                ->color($lateWithoutReason > 0 ? 'danger' : ($late > 0 ? 'warning' : 'gray'))
                ->url($stageUrl($lateWithoutReason > 0 ? 'late_no_reason' : 'late')),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
