<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Kartu "Tempat sampah" di dasbor super admin (spec §3.3), misalnya "7 data menunggu keputusan · 5 dokumen, 2 user".
 * Tautannya membuka menu dengan data terbanyak, tersaring "Hanya data dihapus". Disembunyikan bila tempat sampah kosong.
 * Tampil di bagian atas dasbor, tepat setelah peringatan pengingat dan pengajuan yang menunggu tindakan.
 */
class TrashOverview extends StatsOverviewWidget
{
    /** Nilai filter `trashed` (TernaryFilter) untuk "Hanya data dihapus". */
    private const ONLY_TRASHED = '0';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return static::isSuperAdmin() && static::trashedCounts()->sum('count') > 0;
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    /**
     * Pengganti pemeriksaan bawaan Filament pada setiap poll: canView() ikut bergantung pada isi tempat sampah, jadi setelah
     * data terakhir dipulihkan poll berikutnya akan gagal 403. Saat poll cukup diperiksa bahwa user masih super admin.
     */
    public function hydrateCanAuthorizeAccess(): void
    {
        abort_unless(static::isSuperAdmin(), 403);
    }

    /** Izin sudah diperiksa saat mount dan setiap poll; angka boleh nol bila tempat sampah sudah dikosongkan sejak halaman dibuka. */
    protected function getStats(): array
    {
        $counts = static::trashedCounts()->filter(fn (array $menu): bool => $menu['count'] > 0);
        $biggest = $counts->sortByDesc('count')->first();

        return [
            Stat::make('Tempat sampah', $counts->sum('count'))
                ->description($counts->isEmpty() ? 'Kosong' : 'data menunggu keputusan · '.$counts->map(fn (array $menu): string => "{$menu['count']} {$menu['noun']}")->implode(', '))
                ->descriptionIcon(Heroicon::OutlinedTrash)
                ->color('gray')
                ->url($biggest === null ? null : $biggest['resource']::getUrl('index', ['filters' => ['trashed' => ['value' => self::ONLY_TRASHED]]])),
        ];
    }

    protected function getColumns(): int
    {
        return 1;
    }

    private static function isSuperAdmin(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Jumlah data di tempat sampah per menu, dalam urutan menu.
     *
     * @return Collection<int, array{noun: string, count: int, resource: class-string}>
     */
    private static function trashedCounts(): Collection
    {
        return collect([
            ['noun' => 'dokumen', 'model' => Document::class, 'resource' => DocumentResource::class],
            ['noun' => 'badan usaha', 'model' => Company::class, 'resource' => CompanyResource::class],
            ['noun' => 'jenis dokumen', 'model' => DocumentType::class, 'resource' => DocumentTypeResource::class],
            ['noun' => 'user', 'model' => User::class, 'resource' => UserResource::class],
            ['noun' => 'pengajuan', 'model' => DocumentRequest::class, 'resource' => DocumentRequestResource::class],
        ])->map(fn (array $menu): array => ['noun' => $menu['noun'], 'count' => $menu['model']::onlyTrashed()->count(), 'resource' => $menu['resource']])->values();
    }
}
