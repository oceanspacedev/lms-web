<?php

namespace App\Filament\Concerns;

use App\Filament\Resources\Users\UserResource;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\User;
use App\Services\Trash\TrashManager;
use App\Services\Trash\TrashUsage;
use App\Services\UserDeactivator;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource as FilamentResource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Aksi tempat sampah yang sama untuk Dokumen, Badan Usaha, Jenis Dokumen, User, dan Pengajuan Dokumen (spec §3.1–§3.4).
 * Semua tindakan lewat TrashManager. Hak akses lewat policy: hapus = Delete / DeleteAny, pulihkan dan hapus permanen = super admin.
 * Penolakan dari TrashManager tampil sebagai notifikasi bahaya, tidak pernah sebagai galat 500.
 * `$noun` adalah sebutan jenis data dalam huruf kecil, misalnya "badan usaha", untuk judul modal, tooltip, dan notifikasi.
 * Warna dan ikon: Hapus abu-abu dengan ikon tempat sampah (masih bisa dipulihkan), Pulihkan abu-abu, Hapus permanen merah
 * dengan ikon x-circle; tombol modalnya "Ya, hapus" (merah), "Ya, pulihkan" (utama), "Ya, hapus permanen" (merah).
 */
class TrashActions
{
    public const IRREVERSIBLE = 'Tindakan ini tidak bisa dibatalkan.';

    /** Nilai filter `trashed` (TernaryFilter) untuk "Hanya data dihapus". */
    public const ONLY_TRASHED = '0';

    /** Ikon Hapus permanen, sengaja berbeda dari ikon tempat sampah milik Hapus. */
    public const FORCE_DELETE_ICON = 'heroicon-o-x-circle';

    /**
     * Hapus satu data ke tempat sampah (abu-abu, karena masih bisa dipulihkan). Untuk user aktif, modal memakai alur
     * Nonaktifkan: tugas dialihkan ke pengganti yang dipilih.
     */
    public static function delete(string $noun): DeleteAction
    {
        return DeleteAction::make()->label('Hapus')->tooltip("Hapus {$noun}")->color('gray')->icon('heroicon-o-trash')
            ->authorize('delete')
            ->modalHeading("Hapus {$noun} ini?")
            ->modalDescription(fn (Model $record): HtmlString => self::deleteDescription($record, $noun))
            ->modalSubmitAction(fn (Action $action): Action => $action->color('danger'))
            ->modalSubmitActionLabel('Ya, hapus')
            ->modalCancelActionLabel('Kembali')
            ->schema(fn (Model $record): array => self::isActiveUser($record) ? UserResource::replacementFields($record) : [])
            ->mountUsing(function (DeleteAction $action, Model $record, ?Schema $schema): void {
                $message = self::isActiveUser($record) ? app(UserDeactivator::class)->missingCandidatesMessage($record) : null;
                if ($message !== null) {
                    Notification::make()->danger()->title('User belum bisa dihapus')->body($message)->persistent()->send();
                    $action->cancel();
                }
                $schema?->fill();
            })
            ->action(function (DeleteAction $action, Model $record, array $data): void {
                self::attempt($action, 'Belum bisa dihapus', fn () => app(TrashManager::class)->trash($record, auth()->user(), self::trashData($record, $data)));
                $action->success();
            })
            ->successNotificationTitle(ucfirst($noun).' dipindahkan ke tempat sampah');
    }

    /** Pulihkan satu data. User kembali nonaktif, jadi notifikasinya menunjuk ke tombol Aktifkan kembali di halaman user. */
    public static function restore(string $noun): RestoreAction
    {
        return RestoreAction::make()->label('Pulihkan')->tooltip("Pulihkan {$noun}")->color('gray')->icon('heroicon-o-arrow-uturn-left')
            ->authorize('restore')
            ->modalHeading("Pulihkan {$noun} ini?")
            ->modalDescription(fn (Model $record): string => self::restoreDescription($record::class, $noun))
            ->modalSubmitAction(fn (Action $action): Action => $action->color('primary'))
            ->modalSubmitActionLabel('Ya, pulihkan')
            ->modalCancelActionLabel('Kembali')
            ->action(function (RestoreAction $action, Model $record): void {
                self::attempt($action, 'Belum bisa dipulihkan', fn () => app(TrashManager::class)->restore($record, auth()->user()));
                $action->success();
            })
            ->successNotificationTitle(ucfirst($noun).' dipulihkan')
            ->successNotification(fn (Notification $notification, Model $record): Notification => $record instanceof User
                ? $notification->title('User dipulihkan dalam keadaan nonaktif')->body('Klik tombol Aktifkan kembali di halaman user.')
                    ->actions([Action::make('openUser')->label('Buka user')->url(UserResource::getUrl('edit', ['record' => $record]))])
                : $notification);
    }

    /**
     * Hapus permanen satu data di tempat sampah. Keterkaitannya dihitung saat modal dibuka, sekali per render untuk deskripsi
     * dan tombolnya: bila data ini atau anaknya yang ikut terhapus masih dipakai data aktif, modal menyebut alasannya dan
     * tombol hapus permanen dinonaktifkan. TrashManager tetap memeriksa ulang saat dijalankan.
     */
    public static function forceDelete(string $noun): ForceDeleteAction
    {
        // Satu pemeriksaan per data untuk deskripsi dan tombol modal; aksi dibuat ulang tiap permintaan Livewire, jadi tidak basi.
        /** @var array<string, array{usage: TrashUsage, blockMessage: ?string}> $checks */
        $checks = [];
        $check = function (Model $record) use (&$checks): array {
            return $checks[$record::class.':'.$record->getKey()] ??= self::forceDeleteCheck($record);
        };

        return ForceDeleteAction::make()->label('Hapus permanen')->tooltip("Hapus permanen {$noun}")->color('danger')
            ->icon(self::FORCE_DELETE_ICON)->modalIcon(self::FORCE_DELETE_ICON)
            ->authorize('forceDelete')
            ->modalHeading("Hapus permanen {$noun} ini?")
            ->modalDescription(function (Model $record) use ($check, $noun): string {
                ['usage' => $usage, 'blockMessage' => $blockMessage] = $check($record);

                return $blockMessage ?? self::forceDeleteDescription($record::class, $usage->trashed, "{$noun} ini");
            })
            ->modalSubmitAction(fn (Action $action, Model $record): Action => $action->color('danger')
                ->disabled($check($record)['blockMessage'] !== null))
            ->modalSubmitActionLabel('Ya, hapus permanen')
            ->modalCancelActionLabel('Kembali')
            ->action(function (ForceDeleteAction $action, Model $record): void {
                self::attempt($action, 'Hapus permanen ditolak', fn () => app(TrashManager::class)->forceDelete($record, auth()->user()));
                $action->success();
            })
            ->successNotificationTitle(ucfirst($noun).' dihapus permanen');
    }

    /** Hapus massal ke tempat sampah; data yang tidak boleh dihapus dilewati beserta alasannya. */
    public static function bulkDelete(string $noun): DeleteBulkAction
    {
        return self::configureBulk(DeleteBulkAction::make()->label('Hapus terpilih')->color('gray')->icon('heroicon-o-trash'), $noun, trashed: false, verb: 'dihapus')
            ->authorize('deleteAny')
            ->hidden(fn (HasTable $livewire): bool => self::trashedFilterValue($livewire) === false)
            ->modalHeading(fn (Collection $records): string => self::bulkHeading('Hapus', $noun, $records, trashed: false))
            ->modalDescription(fn (DeleteBulkAction $action, Collection $records): string => self::bulkDeleteDescription($action->getModel(), self::selection($records, trashed: false), $noun))
            ->modalSubmitAction(fn (Action $action): Action => $action->color('danger'))
            ->modalSubmitActionLabel('Ya, hapus')
            ->action(fn (Collection $records) => self::runBulk('trash', self::selection($records, trashed: false), "{$noun} dipindahkan ke tempat sampah"));
    }

    public static function bulkRestore(string $noun): RestoreBulkAction
    {
        return self::configureBulk(RestoreBulkAction::make()->label('Pulihkan terpilih')->color('gray')->icon('heroicon-o-arrow-uturn-left'), $noun, trashed: true, verb: 'dipulihkan')
            ->authorize('restoreAny')
            ->hidden(fn (HasTable $livewire): bool => self::trashedFilterValue($livewire) === null)
            ->modalHeading(fn (Collection $records): string => self::bulkHeading('Pulihkan', $noun, $records, trashed: true))
            ->modalDescription(fn (RestoreBulkAction $action): string => self::restoreDescription($action->getModel(), $noun))
            ->modalSubmitAction(fn (Action $action): Action => $action->color('primary'))
            ->modalSubmitActionLabel('Ya, pulihkan')
            ->action(fn (Collection $records) => self::runBulk('restore', self::selection($records, trashed: true), "{$noun} dipulihkan"));
    }

    /** Hapus permanen massal: data yang masih dipakai dilewati, sisanya tetap diproses. */
    public static function bulkForceDelete(string $noun): ForceDeleteBulkAction
    {
        return self::configureBulk(ForceDeleteBulkAction::make()->label('Hapus permanen terpilih')->color('danger')->icon(self::FORCE_DELETE_ICON)->modalIcon(self::FORCE_DELETE_ICON),
            $noun, trashed: true, verb: 'dihapus permanen')
            ->authorize('forceDeleteAny')
            ->hidden(fn (HasTable $livewire): bool => self::trashedFilterValue($livewire) === null)
            ->modalHeading(fn (Collection $records): string => self::bulkHeading('Hapus permanen', $noun, $records, trashed: true))
            ->modalDescription(function (ForceDeleteBulkAction $action, Collection $records) use ($noun): string {
                $selected = self::selection($records, trashed: true);
                // Ditolak bila data itu sendiri atau anaknya yang ikut terhapus masih dipakai: sama dengan pemeriksaan saat dijalankan.
                [$blocked, $allowed] = $selected->map(fn (Model $record): array => self::forceDeleteCheck($record))
                    ->partition(fn (array $check): bool => $check['blockMessage'] !== null);
                $description = self::forceDeleteDescription($action->getModel(), $allowed->flatMap(fn (array $check): array => $check['usage']->trashed)->all(), "{$noun} terpilih");

                return $blocked->isEmpty() ? $description : "{$description} {$blocked->count()} dari {$selected->count()} akan ditolak karena masih dipakai.";
            })
            ->modalSubmitAction(fn (Action $action): Action => $action->color('danger'))
            ->modalSubmitActionLabel('Ya, hapus permanen')
            ->action(fn (Collection $records) => self::runBulk('forceDelete', self::selection($records, trashed: true), "{$noun} dihapus permanen"));
    }

    /** @return list<DeleteBulkAction|RestoreBulkAction|ForceDeleteBulkAction> */
    public static function bulkActions(string $noun): array
    {
        return [self::bulkDelete($noun), self::bulkRestore($noun), self::bulkForceDelete($noun)];
    }

    /** Filter tempat sampah, hanya untuk super admin; untuk pengguna lain filter ini tidak tampil dan tidak berlaku. */
    public static function filter(): TrashedFilter
    {
        return TrashedFilter::make()->label('Data dihapus')
            ->placeholder('Tanpa data dihapus')->trueLabel('Dengan data dihapus')->falseLabel('Hanya data dihapus')
            ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false);
    }

    /**
     * Kolom "Dihapus" berisi waktu dan penghapusnya, misalnya "05/11/2026 16:00 WIB oleh Admin Pusat"; tanda "Dihapus" ada di kolom status.
     * Tampil hanya saat super admin menyalakan filter Data dihapus, dan disembunyikan di layar sempit.
     */
    public static function deletedColumn(): TextColumn
    {
        return TextColumn::make('deleted_at')->label('Dihapus')
            ->formatStateUsing(fn (Model $record): ?string => self::deletedDescription($record))
            ->placeholder('—')
            ->visibleFrom('md')
            ->visible(fn (HasTable $livewire): bool => self::trashedFilterValue($livewire) !== null);
    }

    /**
     * Batas layar kolom status: saat melihat isi tempat sampah saja, kolom status baru tampil mulai layar `md`, supaya di layar HP
     * tombol Lihat, Pulihkan, dan Hapus permanen muat tanpa menggeser tabel. Di tampilan lain kolom status tampil di semua layar.
     *
     * @return Closure(HasTable): ?string
     */
    public static function statusVisibleFrom(): Closure
    {
        return fn (HasTable $livewire): ?string => self::isShowingOnlyTrashed($livewire) ? 'md' : null;
    }

    /** Aksi "Lihat {noun}" (ikon mata) untuk baris di tempat sampah, pengganti aksi Ubah: membuka halaman detail yang hanya-baca. */
    public static function viewTrashed(string $noun): Action
    {
        return Action::make('view')->label("Lihat {$noun}")->icon('heroicon-o-eye')->color('gray')->iconButton()->tooltip("Lihat {$noun}")
            ->url(fn (Model $record, HasTable $livewire): string => $livewire::getResource()::getUrl('edit', ['record' => $record]))
            ->visible(fn (Model $record): bool => $record->trashed());
    }

    /**
     * Judul tabel kosong: "Tempat sampah kosong" saat tempat sampah memang kosong (lihat isTrashEmptyView()), selain itu `$otherwise`.
     *
     * @param  string|(Closure(HasTable): string)  $otherwise
     * @return Closure(HasTable): string
     */
    public static function emptyStateHeading(string|Closure $otherwise): Closure
    {
        return fn (HasTable $livewire): string => self::isTrashEmptyView($livewire) ? 'Tempat sampah kosong' : value($otherwise, $livewire);
    }

    /**
     * Keterangan tabel kosong: "Tidak ada {noun} di tempat sampah." saat tempat sampah memang kosong, selain itu `$otherwise`.
     *
     * @param  string|(Closure(HasTable): ?string)|null  $otherwise
     * @return Closure(HasTable): ?string
     */
    public static function emptyStateDescription(string $noun, string|Closure|null $otherwise): Closure
    {
        return fn (HasTable $livewire): ?string => self::isTrashEmptyView($livewire) ? "Tidak ada {$noun} di tempat sampah." : value($otherwise, $livewire);
    }

    /**
     * Daftar resource tersaring "Hanya data dihapus": tujuan kartu dasbor, tombol Kembali, dan hapus permanen dari halaman detail.
     *
     * @param  class-string<FilamentResource>  $resource
     */
    public static function trashIndexUrl(string $resource): string
    {
        return $resource::getUrl('index', ['filters' => ['trashed' => ['value' => self::ONLY_TRASHED]]]);
    }

    /** Pemberitahuan di atas halaman detail data yang ada di tempat sampah. */
    public static function trashedNotice(Model $record): string
    {
        return 'Data ini ada di tempat sampah — dihapus '.self::deletedDescription($record).'. Pulihkan untuk mengubahnya.';
    }

    /**
     * Tabel kosong karena tempat sampah memang kosong: filter "Hanya data dihapus" aktif tanpa pencarian (umum maupun per kolom).
     * Bila ada pencarian, keadaan kosong biasa milik tabel itu yang tampil.
     */
    private static function isTrashEmptyView(HasTable $livewire): bool
    {
        return self::isShowingOnlyTrashed($livewire) && ! $livewire->hasTableSearch() && array_filter($livewire->getTableColumnSearches(), filled(...)) === [];
    }

    /** Super admin sedang melihat isi tempat sampah saja (filter "Hanya data dihapus"). */
    public static function isShowingOnlyTrashed(HasTable $livewire): bool
    {
        return self::trashedFilterValue($livewire) === false;
    }

    /**
     * Nilai filter Data dihapus yang berlaku: true = dengan data dihapus, false = hanya data dihapus, null = tanpa data dihapus.
     * Untuk selain super admin selalu null, karena filternya tersembunyi dan tidak diterapkan walaupun ada di URL.
     */
    private static function trashedFilterValue(HasTable $livewire): ?bool
    {
        $value = $livewire->getTableFilterState('trashed')['value'] ?? null;
        if (! (auth()->user()?->isSuperAdmin() ?? false) || blank($value)) {
            return null;
        }

        return (bool) $value;
    }

    /**
     * Query route binding halaman detail: super admin juga bisa membuka data di tempat sampah (baca-saja); pengguna lain mendapat 404.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function routeBindingQuery(Builder $query): Builder
    {
        return auth()->user()?->isSuperAdmin() ? $query->withoutGlobalScopes([SoftDeletingScope::class]) : $query;
    }

    /**
     * Jalankan tindakan TrashManager; penolakannya menjadi notifikasi bahaya dan modal tetap terbuka.
     */
    private static function attempt(Action $action, string $failureTitle, Closure $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title($failureTitle)->body(self::firstMessage($exception))->persistent()->send();
            $action->halt();
        }
    }

    /**
     * Bagian yang sama untuk ketiga aksi massal. Bila tidak satu pun baris terpilih relevan (misalnya Hapus terpilih pada
     * baris yang sudah di tempat sampah), modal tidak dibuka dan muncul peringatan "Tidak ada {noun} di pilihan ini yang bisa {verb}."
     *
     * @template TAction of BulkAction
     *
     * @param  TAction  $action
     * @param  bool  $trashed  Baris yang relevan: true = di tempat sampah (pulihkan, hapus permanen), false = aktif (hapus).
     * @return TAction
     */
    private static function configureBulk(BulkAction $action, string $noun, bool $trashed, string $verb): BulkAction
    {
        return $action->modalCancelActionLabel('Kembali')->successNotification(null)
            ->mountUsing(function (BulkAction $action, Collection $records, ?Schema $schema) use ($noun, $trashed, $verb): void {
                if (self::selection($records, $trashed)->isEmpty()) {
                    Notification::make()->warning()->title("Tidak ada {$noun} di pilihan ini yang bisa {$verb}.")->send();
                    $action->cancel();
                }
                $schema?->fill();
            });
    }

    /**
     * Judul modal massal yang hanya menghitung baris relevan, misalnya "Hapus 3 badan usaha? (2 baris lain dilewati.)".
     *
     * @param  Collection<int, Model>  $records
     */
    private static function bulkHeading(string $verb, string $noun, Collection $records, bool $trashed): string
    {
        $relevant = self::selection($records, $trashed)->count();
        $skipped = $records->count() - $relevant;

        return "{$verb} {$relevant} {$noun}?".($skipped > 0 ? " ({$skipped} baris lain dilewati.)" : '');
    }

    /**
     * Notifikasi hasil, misalnya "4 badan usaha dipindahkan ke tempat sampah, 1 dilewati: PT A (alasan)".
     * Satu-satunya notifikasi aksi massal; notifikasi sukses bawaan Filament dimatikan di tiap aksi.
     * Sukses hanya bila semua diproses; ada yang dilewati atau tidak ada yang diproses menjadi peringatan.
     *
     * @param  'trash'|'restore'|'forceDelete'  $operation
     * @param  Collection<int, Model>  $records
     */
    private static function runBulk(string $operation, Collection $records, string $doneLabel): void
    {
        $result = app(TrashManager::class)->bulk($operation, $records, auth()->user());
        $title = "{$result['done']} {$doneLabel}";
        if ($result['skipped'] !== []) {
            $title .= ', '.count($result['skipped']).' dilewati: '
                .implode('; ', array_map(fn (array $skipped): string => "{$skipped['label']} ({$skipped['reason']})", $result['skipped']));
        }

        $notification = Notification::make()->title($title);
        if ($result['skipped'] === [] && $result['done'] > 0) {
            $notification->success();
        } else {
            $notification->warning()->persistent();
        }
        $notification->send();
    }

    /**
     * Baris terpilih yang relevan: hapus hanya untuk data aktif, pulihkan dan hapus permanen hanya untuk data di tempat sampah.
     *
     * @param  Collection<int, Model>  $records
     * @return Collection<int, Model>
     */
    private static function selection(Collection $records, bool $trashed): Collection
    {
        return $records->filter(fn (Model $record): bool => $record->trashed() === $trashed)->values();
    }

    private static function isActiveUser(Model $record): bool
    {
        return $record instanceof User && $record->isActive();
    }

    /**
     * Isian modal untuk TrashManager. Untuk user aktif, setiap kelompok tugas selalu dikirim (boleh null),
     * karena UserTrashHandler menganggap isian tanpa kunci kelompok sebagai hapus massal dan menolaknya.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function trashData(Model $record, array $data): array
    {
        if (! self::isActiveUser($record)) {
            return $data;
        }

        return [...array_fill_keys(app(UserDeactivator::class)->requiredGroups($record), null), ...$data];
    }

    /**
     * Keterkaitan data untuk modal hapus permanen beserta alasan penolakannya (null bila boleh), dengan pemeriksaan yang sama
     * seperti TrashManager::forceDelete(), termasuk anak di tempat sampah yang ikut terhapus.
     *
     * @return array{usage: TrashUsage, blockMessage: ?string}
     */
    private static function forceDeleteCheck(Model $record): array
    {
        $trash = app(TrashManager::class);
        $usage = $trash->usage($record);

        return ['usage' => $usage, 'blockMessage' => $trash->forceDeleteBlockMessage($record, $usage)];
    }

    /** Penjelasan modal hapus, misalnya "Badan usaha dipindahkan ke tempat sampah dan hilang dari daftar. ..." */
    private static function explanation(string $noun): string
    {
        return ucfirst($noun).' dipindahkan ke tempat sampah dan hilang dari daftar. Super admin bisa memulihkannya atau menghapusnya permanen.';
    }

    /**
     * Saran di modal hapus Pengajuan dan User: menghentikan pengajuan atau melepas staf punya tombol sendiri yang menjaga riwayat.
     *
     * @param  class-string<Model>  $model
     */
    private static function deleteAdvice(string $model): ?string
    {
        return match ($model) {
            DocumentRequest::class => 'Untuk menghentikan pengajuan yang sah, pakai Batalkan — riwayatnya tetap tercatat. Hapus hanya untuk data uji atau salah input.',
            User::class => 'Staf keluar atau pindah? Pakai Nonaktifkan. Hapus hanya untuk user yang salah dibuat.',
            default => null,
        };
    }

    private static function deleteDescription(Model $record, string $noun): HtmlString
    {
        $paragraphs = [e(self::explanation($noun))];
        if ($record instanceof User) {
            $workload = $record->isActive() ? UserResource::workloadSummary($record, forDeletion: true)->toHtml() : '';

            return new HtmlString('<p>'.$paragraphs[0].'</p>'.$workload.'<p>'.e(self::deleteAdvice(User::class)).'</p>');
        }

        $usage = app(TrashManager::class)->usage($record);
        // Dokumen tidak punya nama yang tampil "(dihapus)" di pengajuan; cukup sebut pengajuan asalnya (spec §4.1).
        $usageText = $record instanceof Document ? implode(' ', $usage->notes) : $usage->summary();
        if ($usageText !== '') {
            $paragraphs[] = e($usageText);
        }
        if ($record instanceof Document) {
            $paragraphs[] = 'Pengingat masa berlakunya berhenti dan file tidak ikut dihapus.';
        }
        $advice = self::deleteAdvice($record::class);
        if ($advice !== null) {
            $paragraphs[] = e($advice);
        }

        return new HtmlString(implode('', array_map(fn (string $paragraph): string => "<p>{$paragraph}</p>", $paragraphs)));
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Collection<int, Model>  $records
     */
    private static function bulkDeleteDescription(string $model, Collection $records, string $noun): string
    {
        $trash = app(TrashManager::class);
        $sentence = match ($model) {
            User::class => self::countSentence(
                $records->filter(fn (User $user): bool => $user->isActive() && app(UserDeactivator::class)->requiredGroups($user) !== [])->count(),
                'punya tugas yang harus dialihkan, jadi dilewati; hapus satu per satu.'),
            DocumentRequest::class => self::countSentence(
                $records->filter(fn (DocumentRequest $request): bool => $trash->usage($request)->notes !== [])->count(),
                'masih berjalan; pengingat terkait pengajuan itu akan berhenti.'),
            default => (function () use ($records, $trash): string {
                $usages = $records->map(fn (Model $record): TrashUsage => $trash->usage($record))->filter(fn (TrashUsage $usage): bool => $usage->active !== []);
                $labels = $usages->flatMap(fn (TrashUsage $usage): array => array_column($usage->active, 'label'))->unique()->values()->all();

                return self::countSentence($usages->count(), 'masih dipakai '.implode(' atau ', $labels).'.');
            })(),
        };

        return trim(implode(' ', array_filter([self::explanation($noun), $sentence, self::deleteAdvice($model)])));
    }

    private static function countSentence(int $count, string $predicate): string
    {
        return $count > 0 ? "{$count} di antaranya {$predicate}" : '';
    }

    /** @param  class-string<Model>  $model */
    private static function restoreDescription(string $model, string $noun): string
    {
        return match ($model) {
            Document::class => 'Dokumen muncul kembali di daftar, file bisa diunduh lagi, dan pengingat masa berlaku berjalan lagi.',
            User::class => 'User kembali dalam keadaan nonaktif. Bila user perlu masuk lagi, klik tombol Aktifkan kembali di halaman user.',
            DocumentRequest::class => 'Pengajuan kembali ke status semula. Tahap pengingat yang terlewat selama di tempat sampah tidak dikirim.',
            default => ucfirst($noun).' kembali ke daftar seperti semula.',
        };
    }

    /**
     * "Tindakan ini tidak bisa dibatalkan." ditambah data di tempat sampah yang ikut terhapus permanen, misalnya
     * "3 dokumen di tempat sampah yang memakai badan usaha ini juga akan dihapus permanen beserta semua versi dan filenya." (spec §3.4).
     * Bagian "beserta semua versi dan filenya" hanya bila ada dokumen yang ikut terhapus.
     *
     * @param  class-string<Model>  $model
     * @param  list<array{label: string, count: int, examples: list<string>}>  $cascaded
     */
    private static function forceDeleteDescription(string $model, array $cascaded, string $target): string
    {
        $sentences = [self::IRREVERSIBLE];
        $grouped = collect($cascaded)->groupBy('label');
        $counts = $grouped->map(fn (Collection $entries, string $label): string => $entries->sum('count').' '.$label)->values()->all();
        if ($counts !== []) {
            $sentences[] = TrashUsage::join($counts)." di tempat sampah yang memakai {$target} juga akan dihapus permanen"
                .($grouped->has('dokumen') ? ' beserta semua versi dan filenya.' : '.');
        }
        $sentences[] = match ($model) {
            Document::class => 'Semua versi beserta filenya, riwayat aktivitas, dan log pengingat ikut dihapus.',
            DocumentRequest::class => 'Berkas pengajuan (lampiran, draft, soft file, dan scan) ikut dihapus. Dokumen arsip hasil pengajuan tidak ikut terhapus.',
            default => '',
        };

        return trim(implode(' ', $sentences));
    }

    private static function deletedDescription(Model $record): ?string
    {
        if ($record->deleted_at === null) {
            return null;
        }

        $timezone = config('lms.reminder_timezone');
        $deletedBy = $record->deletedBy?->name;

        return $record->deleted_at->copy()->timezone($timezone)->translatedFormat('d M Y H:i').' '.($timezone === 'Asia/Jakarta' ? 'WIB' : $timezone)
            .($deletedBy === null ? '' : ' oleh '.$deletedBy);
    }

    private static function firstMessage(ValidationException $exception): string
    {
        return (string) (collect($exception->errors())->flatten()->first() ?? $exception->getMessage());
    }
}
