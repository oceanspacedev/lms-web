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
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
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
 * `$noun` adalah sebutan jenis data dalam huruf kecil, misalnya "badan usaha", untuk judul modal.
 */
class TrashActions
{
    public const EXPLANATION = 'Data dipindahkan ke tempat sampah dan hilang dari daftar. Super admin bisa memulihkannya atau menghapusnya permanen.';

    public const IRREVERSIBLE = 'Tindakan ini tidak bisa dibatalkan.';

    /**
     * Hapus satu data ke tempat sampah. Untuk user aktif, modal memakai alur Nonaktifkan: tugas dialihkan ke pengganti yang dipilih.
     */
    public static function delete(string $noun): DeleteAction
    {
        return DeleteAction::make()->label('Hapus')
            ->authorize('delete')
            ->modalHeading("Hapus {$noun} ini?")
            ->modalDescription(fn (Model $record): HtmlString => self::deleteDescription($record))
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
            ->successNotificationTitle('Dipindahkan ke tempat sampah');
    }

    public static function restore(string $noun): RestoreAction
    {
        return RestoreAction::make()->label('Pulihkan')
            ->authorize('restore')
            ->modalHeading("Pulihkan {$noun} ini?")
            ->modalDescription(fn (Model $record): string => self::restoreDescription($record::class))
            ->modalSubmitActionLabel('Ya, pulihkan')
            ->modalCancelActionLabel('Kembali')
            ->action(function (RestoreAction $action, Model $record): void {
                self::attempt($action, 'Belum bisa dipulihkan', fn () => app(TrashManager::class)->restore($record, auth()->user()));
                $action->success();
            })
            ->successNotificationTitle('Data dipulihkan');
    }

    /**
     * Hapus permanen satu data di tempat sampah. Bila masih dipakai data aktif, aksi tetap bisa dijalankan
     * tetapi berakhir dengan notifikasi bahaya berisi alasannya.
     */
    public static function forceDelete(string $noun): ForceDeleteAction
    {
        return ForceDeleteAction::make()->label('Hapus permanen')
            ->authorize('forceDelete')
            ->modalHeading("Hapus permanen {$noun} ini?")
            ->modalDescription(fn (Model $record): string => self::forceDeleteDescription($record::class, app(TrashManager::class)->usage($record)->trashed, "{$noun} ini"))
            ->modalSubmitActionLabel('Ya, hapus permanen')
            ->modalCancelActionLabel('Kembali')
            ->action(function (ForceDeleteAction $action, Model $record): void {
                self::attempt($action, 'Hapus permanen ditolak', fn () => app(TrashManager::class)->forceDelete($record, auth()->user()));
                $action->success();
            })
            ->successNotificationTitle('Dihapus permanen');
    }

    /** Hapus massal ke tempat sampah; data yang tidak boleh dihapus dilewati beserta alasannya. */
    public static function bulkDelete(string $noun): DeleteBulkAction
    {
        return DeleteBulkAction::make()->label('Hapus terpilih')
            ->authorize('deleteAny')
            ->hidden(fn (HasTable $livewire): bool => self::trashedFilterValue($livewire) === false)
            ->modalHeading(fn (Collection $records): string => 'Hapus '.self::selection($records, trashed: false)->count()." {$noun}?")
            ->modalDescription(fn (DeleteBulkAction $action, Collection $records): string => self::bulkDeleteDescription($action->getModel(), self::selection($records, trashed: false)))
            ->modalSubmitActionLabel('Ya, hapus')
            ->modalCancelActionLabel('Kembali')
            ->action(fn (Collection $records) => self::runBulk('trash', self::selection($records, trashed: false), 'dipindahkan ke tempat sampah'))
            ->successNotification(null);
    }

    public static function bulkRestore(string $noun): RestoreBulkAction
    {
        return RestoreBulkAction::make()->label('Pulihkan terpilih')
            ->authorize('restoreAny')
            ->hidden(fn (HasTable $livewire): bool => self::trashedFilterValue($livewire) === null)
            ->modalHeading(fn (Collection $records): string => 'Pulihkan '.self::selection($records, trashed: true)->count()." {$noun}?")
            ->modalDescription(fn (RestoreBulkAction $action): string => self::restoreDescription($action->getModel()))
            ->modalSubmitActionLabel('Ya, pulihkan')
            ->modalCancelActionLabel('Kembali')
            ->action(fn (Collection $records) => self::runBulk('restore', self::selection($records, trashed: true), 'dipulihkan'))
            ->successNotification(null);
    }

    /** Hapus permanen massal: data yang masih dipakai dilewati, sisanya tetap diproses. */
    public static function bulkForceDelete(string $noun): ForceDeleteBulkAction
    {
        return ForceDeleteBulkAction::make()->label('Hapus permanen terpilih')
            ->authorize('forceDeleteAny')
            ->hidden(fn (HasTable $livewire): bool => self::trashedFilterValue($livewire) === null)
            ->modalHeading(fn (Collection $records): string => 'Hapus permanen '.self::selection($records, trashed: true)->count()." {$noun}?")
            ->modalDescription(function (ForceDeleteBulkAction $action, Collection $records) use ($noun): string {
                $trash = app(TrashManager::class);
                $cascaded = self::selection($records, trashed: true)->flatMap(fn (Model $record): array => $trash->usage($record)->trashed)->all();

                return self::forceDeleteDescription($action->getModel(), $cascaded, "{$noun} terpilih").' Data yang masih dipakai data aktif dilewati.';
            })
            ->modalSubmitActionLabel('Ya, hapus permanen')
            ->modalCancelActionLabel('Kembali')
            ->action(fn (Collection $records) => self::runBulk('forceDelete', self::selection($records, trashed: true), 'dihapus permanen'))
            ->successNotification(null);
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

    /** Badge "Dihapus" dengan waktu dan penghapusnya; tampil hanya saat super admin menyalakan filter Data dihapus. */
    public static function deletedColumn(): TextColumn
    {
        return TextColumn::make('deleted_at')->label('Dihapus')->badge()->color('gray')->icon('heroicon-o-trash')
            ->formatStateUsing(fn (): string => 'Dihapus')
            ->description(fn (Model $record): ?string => self::deletedDescription($record))
            ->placeholder('—')
            ->visible(fn (HasTable $livewire): bool => self::trashedFilterValue($livewire) !== null);
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
     * Notifikasi hasil, misalnya "4 dipindahkan ke tempat sampah, 1 dilewati: PT A (alasan)".
     * Satu-satunya notifikasi aksi massal; notifikasi sukses bawaan Filament dimatikan di tiap aksi.
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
        match (true) {
            $result['skipped'] === [] => $notification->success(),
            $result['done'] > 0 => $notification->warning()->persistent(),
            default => $notification->danger()->persistent(),
        };
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

    private static function deleteDescription(Model $record): HtmlString
    {
        $paragraphs = [e(self::EXPLANATION)];
        if ($record instanceof User) {
            return new HtmlString('<p>'.$paragraphs[0].'</p>'.($record->isActive() ? UserResource::workloadSummary($record)->toHtml() : ''));
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

        return new HtmlString(implode('', array_map(fn (string $paragraph): string => "<p>{$paragraph}</p>", $paragraphs)));
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Collection<int, Model>  $records
     */
    private static function bulkDeleteDescription(string $model, Collection $records): string
    {
        $trash = app(TrashManager::class);
        $sentence = match ($model) {
            User::class => self::countSentence(
                $records->filter(fn (User $user): bool => $user->isActive() && app(UserDeactivator::class)->requiredGroups($user) !== [])->count(),
                'punya tugas yang harus dialihkan, jadi dilewati; hapus satu per satu.'),
            DocumentRequest::class => self::countSentence(
                $records->filter(fn (DocumentRequest $request): bool => $trash->usage($request)->notes !== [])->count(),
                'masih berjalan; pengingat ke pengaju akan berhenti.'),
            default => (function () use ($records, $trash): string {
                $usages = $records->map(fn (Model $record): TrashUsage => $trash->usage($record))->filter(fn (TrashUsage $usage): bool => $usage->active !== []);
                $labels = $usages->flatMap(fn (TrashUsage $usage): array => array_column($usage->active, 'label'))->unique()->values()->all();

                return self::countSentence($usages->count(), 'masih dipakai '.implode(' atau ', $labels).'.');
            })(),
        };

        return trim(self::EXPLANATION.' '.$sentence);
    }

    private static function countSentence(int $count, string $predicate): string
    {
        return $count > 0 ? "{$count} di antaranya {$predicate}" : '';
    }

    /** @param  class-string<Model>  $model */
    private static function restoreDescription(string $model): string
    {
        return match ($model) {
            Document::class => 'Dokumen muncul kembali di daftar, file bisa diunduh lagi, dan pengingat masa berlaku berjalan lagi.',
            User::class => 'User kembali dalam keadaan nonaktif. Aktifkan kembali lewat tombol Aktifkan kembali bila user perlu masuk lagi.',
            DocumentRequest::class => 'Pengajuan kembali ke status semula. Tahap pengingat yang terlewat selama di tempat sampah tidak dikirim.',
            default => 'Data kembali ke daftar seperti semula.',
        };
    }

    /**
     * "Tindakan ini tidak bisa dibatalkan." ditambah data di tempat sampah yang ikut terhapus permanen, misalnya
     * "3 dokumen di tempat sampah yang memakai badan usaha ini juga akan dihapus permanen." (spec §3.4).
     *
     * @param  class-string<Model>  $model
     * @param  list<array{label: string, count: int, examples: list<string>}>  $cascaded
     */
    private static function forceDeleteDescription(string $model, array $cascaded, string $target): string
    {
        $sentences = [self::IRREVERSIBLE];
        $counts = collect($cascaded)->groupBy('label')->map(fn (Collection $entries, string $label): string => $entries->sum('count').' '.$label)->values()->all();
        if ($counts !== []) {
            $sentences[] = TrashUsage::join($counts)." di tempat sampah yang memakai {$target} juga akan dihapus permanen.";
        }
        $sentences[] = match ($model) {
            Document::class => 'Semua versi beserta filenya, riwayat aktivitas, dan log pengingat ikut dihapus.',
            DocumentRequest::class => 'Berkas pengajuan (lampiran, draft, dan scan) ikut dihapus. Dokumen arsip hasil pengajuan tidak ikut terhapus.',
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

        return $record->deleted_at->copy()->timezone($timezone)->format('d/m/Y H:i').' '.($timezone === 'Asia/Jakarta' ? 'WIB' : $timezone)
            .($deletedBy === null ? '' : ' oleh '.$deletedBy);
    }

    private static function firstMessage(ValidationException $exception): string
    {
        return (string) (collect($exception->errors())->flatten()->first() ?? $exception->getMessage());
    }
}
