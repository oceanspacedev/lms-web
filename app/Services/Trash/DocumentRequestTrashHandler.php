<?php

namespace App\Services\Trash;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\User;
use App\Services\DocumentRequestReminderSender;
use App\Services\HandoverReminderSender;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengajuan Dokumen (spec §4.5): boleh dihapus di status apa pun dan tidak pernah dipakai data lain.
 * Di tempat sampah pengingat berhenti dan notifikasi antre dibatalkan; pengaju tidak diberi tahu.
 * Saat pulih, tahap pengingat yang jatuh tempo selama di tempat sampah dianggap sudah lewat.
 * Hapus permanen menghapus berkas pengajuan di disk `local`; dokumen arsip hasil pengajuan tidak disentuh.
 */
class DocumentRequestTrashHandler implements TrashHandler
{
    /** Status pengajuan yang masih berjalan dan punya pengingat ke pengaju atau staf. */
    private const RUNNING_STATUSES = ['submitted', 'signing', 'handover'];

    /** Folder berkas pengajuan di disk `local`; path di luar folder ini tidak pernah dihapus. */
    private const FILE_DIRECTORY = 'lms/requests/';

    public function __construct(private HandoverReminderSender $handoverReminders, private DocumentRequestReminderSender $waitingReminders) {}

    public function supports(Model $record): bool
    {
        return $record instanceof DocumentRequest;
    }

    /**
     * @param  DocumentRequest  $record
     */
    public function usage(Model $record): TrashUsage
    {
        $notes = in_array($record->status, self::RUNNING_STATUSES, true)
            ? ['Pengajuan ini masih berjalan ('.DocumentRequest::statusLabel($record->status).'). Pengingat terkait pengajuan ini akan berhenti.']
            : [];

        return new TrashUsage(notes: $notes, subjectName: 'pengajuan');
    }

    /**
     * @param  DocumentRequest  $record
     */
    public function beforeTrash(Model $record, User $actor, array $data): void
    {
        $record->notifications()->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'cancelled', 'error_message' => DocumentRequestNotification::REQUEST_TRASHED_MESSAGE]);
    }

    /**
     * Batas penyerahan dan hari terlambat tidak digeser. Notifikasi yang dibatalkan saat penghapusan tidak dihidupkan lagi.
     *
     * @param  DocumentRequest  $record
     */
    public function afterRestore(Model $record, User $actor): void
    {
        $this->handoverReminders->skipStagesAlreadyReached($record);
        $this->waitingReminders->skipDueReminders($record);
    }

    /**
     * Notifikasi dan alasan terlambat ikut terhapus lewat foreign key `cascade`.
     *
     * @param  DocumentRequest  $record
     */
    public function purge(Model $record, User $actor): array
    {
        $paths = [...array_values($record->attachments ?? []), $record->draft_path, $record->signed_path];
        $files = collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path) && str_starts_with($path, self::FILE_DIRECTORY) && ! str_contains($path, '..'))
            ->unique()
            ->map(fn (string $path): array => ['disk' => 'local', 'path' => $path])
            ->values()
            ->all();
        $record->forceDelete();

        return $files;
    }

    public function trashDenialReason(Model $record, User $actor): ?string
    {
        return null;
    }
}
