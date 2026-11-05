<?php

namespace App\Services\Trash;

use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentRequest;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Dokumen: dipakai pengajuan yang menunjuknya lewat `document_requests.document_id` (spec §4.1).
 * Masuk tempat sampah dan pulih tetap tercatat di riwayat aktivitas dokumen (`deleted` / `restored`); file tidak disentuh.
 * Hapus permanen menghapus versi beserta filenya, aktivitas, dan log pengingat.
 */
class DocumentTrashHandler implements TrashHandler
{
    public function supports(Model $record): bool
    {
        return $record instanceof Document;
    }

    /**
     * @param  Document  $record
     */
    public function usage(Model $record): TrashUsage
    {
        $requests = fn (): Builder => DocumentRequest::query()->where('document_id', $record->getKey());
        $requestNumbers = DocumentRequest::withTrashed()->where('document_id', $record->getKey())->orderBy('id')->pluck('id')
            ->map(fn (int $id): string => '#'.$id)->all();

        return new TrashUsage(
            active: [self::entry($requests())],
            trashed: [self::entry($requests()->onlyTrashed())],
            notes: $requestNumbers === [] ? [] : ['Dokumen ini hasil pengajuan '.implode(', ', $requestNumbers).'.'],
            subjectName: 'dokumen',
            blockTemplate: TrashUsage::BLOCK_DOCUMENT,
        );
    }

    /**
     * @param  Document  $record
     */
    public function beforeTrash(Model $record, User $actor, array $data): void
    {
        $this->recordActivity($record, $actor, 'deleted');
    }

    /**
     * @param  Document  $record
     */
    public function afterRestore(Model $record, User $actor): void
    {
        $this->recordActivity($record, $actor, 'restored');
    }

    /**
     * Urutan mengikuti foreign key `restrict`: pengajuan di tempat sampah, log pengingat, aktivitas, `current_version_id`, versi, lalu dokumen.
     * Hook `deleting` DocumentVersion dan DocumentActivity sengaja melarang penghapusan; hanya di sini dilewati lewat query builder.
     *
     * @param  Document  $record
     */
    public function purge(Model $record, User $actor): array
    {
        $trash = app(TrashManager::class);
        $files = [];
        $requests = DocumentRequest::onlyTrashed()->where('document_id', $record->getKey())->orderBy('id')->get();
        foreach ($requests as $request) {
            array_push($files, ...$trash->purge($request, $actor));
        }

        $versions = DocumentVersion::query()->where('document_id', $record->getKey())->orderBy('id')->get(['id', 'disk', 'file_path']);
        foreach ($versions as $version) {
            $files[] = ['disk' => $version->disk ?? 's3', 'path' => $version->file_path];
        }

        ReminderLog::query()->whereIn('document_version_id', $versions->modelKeys())->toBase()->delete();
        DocumentActivity::query()->where('document_id', $record->getKey())->toBase()->delete();
        $record->newQueryWithoutScopes()->whereKey($record->getKey())->toBase()->update(['current_version_id' => null]);
        DocumentVersion::query()->where('document_id', $record->getKey())->toBase()->delete();
        $record->forceDelete();

        return $files;
    }

    public function trashDenialReason(Model $record, User $actor): ?string
    {
        return null;
    }

    private function recordActivity(Document $document, User $actor, string $event): void
    {
        $document->activities()->create(['document_version_id' => $document->current_version_id, 'user_id' => $actor->id, 'event' => $event]);
    }

    /**
     * @return array{label: string, count: int, examples: list<string>}
     */
    private static function entry(Builder $requests): array
    {
        return [
            'label' => 'pengajuan',
            'count' => (clone $requests)->count(),
            'examples' => $requests->orderBy('id')->limit(TrashUsage::EXAMPLE_LIMIT)->pluck('id')->map(fn (int $id): string => '#'.$id)->all(),
        ];
    }
}
