<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\TrashActions;
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
use Filament\Resources\Resource as FilamentResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Kartu "Tempat sampah" di dasbor super admin (spec §3.3): satu kartu per menu yang punya isi tempat sampah, misalnya
 * "Dokumen · 5 · menunggu keputusan". Tiap kartu membuka menunya, tersaring "Hanya data dihapus". Disembunyikan bila tempat sampah kosong.
 * Tampil di bagian atas dasbor, tepat setelah peringatan pengingat dan pengajuan yang menunggu tindakan.
 */
class TrashOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Tempat sampah';

    /** @var Collection<int, array{count: int, resource: class-string<FilamentResource>}>|null */
    private ?Collection $nonEmptyMenus = null;

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

    /** Izin sudah diperiksa saat mount dan setiap poll; daftar kartu boleh kosong bila tempat sampah sudah dikosongkan sejak halaman dibuka. */
    protected function getStats(): array
    {
        return $this->nonEmptyMenus()
            ->map(fn (array $menu): Stat => Stat::make($menu['resource']::getNavigationLabel(), $menu['count'])
                ->description('menunggu keputusan')
                ->descriptionIcon(Heroicon::OutlinedTrash)
                ->color('gray')
                ->url(TrashActions::trashIndexUrl($menu['resource'])))
            ->all();
    }

    protected function getColumns(): int
    {
        return max(1, min(3, $this->nonEmptyMenus()->count()));
    }

    /**
     * Menu yang punya isi tempat sampah, dihitung sekali per render untuk kartu dan jumlah kolomnya.
     *
     * @return Collection<int, array{count: int, resource: class-string<FilamentResource>}>
     */
    private function nonEmptyMenus(): Collection
    {
        return $this->nonEmptyMenus ??= static::trashedCounts()->filter(fn (array $menu): bool => $menu['count'] > 0)->values();
    }

    private static function isSuperAdmin(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Jumlah data di tempat sampah per menu, dalam urutan menu.
     *
     * @return Collection<int, array{count: int, resource: class-string<FilamentResource>}>
     */
    private static function trashedCounts(): Collection
    {
        return collect([
            [Document::class, DocumentResource::class],
            [Company::class, CompanyResource::class],
            [DocumentType::class, DocumentTypeResource::class],
            [User::class, UserResource::class],
            [DocumentRequest::class, DocumentRequestResource::class],
        ])->map(fn (array $menu): array => ['count' => $menu[0]::onlyTrashed()->count(), 'resource' => $menu[1]])->values();
    }
}
