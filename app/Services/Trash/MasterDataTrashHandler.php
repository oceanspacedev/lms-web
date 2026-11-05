<?php

namespace App\Services\Trash;

use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\User;
use App\Services\UserDeactivator;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Aturan bersama data induk yang dipakai dokumen dan pengajuan lewat satu kolom (Badan Usaha, Jenis Dokumen).
 * Dokumen dan pengajuan aktif menghalangi hapus permanen; yang di tempat sampah ikut dihapus permanen.
 */
abstract class MasterDataTrashHandler implements TrashHandler
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Kolom di `documents` dan `document_requests` yang menunjuk data ini. */
    abstract protected function foreignKey(): string;

    /** Sebutan jenis data dalam huruf kecil, misalnya "badan usaha". */
    abstract protected function subjectName(): string;

    public function supports(Model $record): bool
    {
        return is_a($record, $this->modelClass());
    }

    public function usage(Model $record): TrashUsage
    {
        $documents = fn (): Builder => Document::query()->where($this->foreignKey(), $record->getKey());
        $requests = fn (): Builder => DocumentRequest::query()->where($this->foreignKey(), $record->getKey());
        $documentTitle = fn (Document $document): string => $document->trashLabel();
        $requestNumber = fn (DocumentRequest $request): string => '#'.$request->id;
        $running = $requests()->whereNotIn('status', UserDeactivator::CLOSED_REQUEST_STATUSES)->count();

        return new TrashUsage(
            active: [self::entry('dokumen', $documents(), $documentTitle), self::entry('pengajuan', $requests(), $requestNumber)],
            trashed: [self::entry('dokumen', $documents()->onlyTrashed(), $documentTitle), self::entry('pengajuan', $requests()->onlyTrashed(), $requestNumber)],
            notes: $running > 0 ? ["{$running} pengajuan masih berjalan dan tetap bisa diselesaikan."] : [],
            subjectName: $this->subjectName(),
        );
    }

    public function beforeTrash(Model $record, User $actor, array $data): void {}

    public function afterRestore(Model $record, User $actor): void {}

    /**
     * Pengajuan di tempat sampah dihapus lebih dulu, karena pengajuan Selesai menunjuk dokumen arsipnya.
     */
    public function purge(Model $record): array
    {
        $trash = app(TrashManager::class);
        $files = [];
        foreach ([DocumentRequest::class, Document::class] as $dependentClass) {
            $dependents = $dependentClass::onlyTrashed()->where($this->foreignKey(), $record->getKey())->orderBy('id')->get();
            foreach ($dependents as $dependent) {
                array_push($files, ...$trash->purge($dependent));
            }
        }
        $record->forceDelete();

        return $files;
    }

    public function trashDenialReason(Model $record, User $actor): ?string
    {
        return null;
    }

    /**
     * @param  Closure(Model): string  $example
     * @return array{label: string, count: int, examples: list<string>}
     */
    private static function entry(string $label, Builder $query, Closure $example): array
    {
        return [
            'label' => $label,
            'count' => (clone $query)->count(),
            'examples' => $query->orderBy('id')->limit(TrashUsage::EXAMPLE_LIMIT)->get()->map($example)->values()->all(),
        ];
    }
}
