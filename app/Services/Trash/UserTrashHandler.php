<?php

namespace App\Services\Trash;

use App\Models\Document;
use App\Models\DocumentImport;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\UserDeactivator;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * User (spec §4.4): hapus = nonaktifkan (tugas dialihkan ke pengganti) lalu masuk tempat sampah; pulih tetap nonaktif.
 * Hapus permanen tidak berantai: referensi wajib di data mana pun, termasuk yang di tempat sampah, menghalanginya;
 * referensi yang boleh kosong dikosongkan, dan sisanya diurus foreign key `nullOnDelete`.
 */
class UserTrashHandler implements TrashHandler
{
    /** Alasan user dilewati pada hapus massal: memilih pengganti untuk banyak user sekaligus terlalu rawan salah. */
    public const HAS_WORK_REASON = 'punya tugas yang harus dialihkan, hapus satu per satu';

    public function __construct(private UserDeactivator $deactivator) {}

    public function supports(Model $record): bool
    {
        return $record instanceof User;
    }

    /**
     * Semua referensi di sini menghalangi hapus permanen, termasuk dari data di tempat sampah, sehingga `trashed` selalu kosong.
     *
     * @param  User  $record
     */
    public function usage(Model $record): TrashUsage
    {
        $id = $record->getKey();
        $requestNumber = fn (DocumentRequest $request): string => '#'.$request->id;
        $typeName = fn (DocumentType $type): string => $type->trashLabel();

        return new TrashUsage(
            active: [
                self::entry('dokumen sebagai PIC', Document::withTrashed()->where('pic_user_id', $id), fn (Document $document): string => $document->trashLabel()),
                self::entry('versi dokumen sebagai pengunggah', DocumentVersion::query()->with(['document' => fn ($query) => $query->withTrashed()])->where('uploaded_by', $id),
                    fn (DocumentVersion $version): string => ($version->document?->trashLabel() ?? 'Dokumen').' v'.$version->version_number),
                self::entry('pengajuan sebagai pengaju', DocumentRequest::withTrashed()->where('requester_id', $id), $requestNumber),
                self::entry('pengajuan sebagai PIC', DocumentRequest::withTrashed()->where('pic_user_id', $id), $requestNumber),
                self::entry('jenis dokumen sebagai PIC pengajuan', DocumentType::withTrashed()->where('request_pic_id', $id), $typeName),
                self::entry('jenis dokumen sebagai pemeriksa', DocumentType::withTrashed()->where('request_reviewer_id', $id), $typeName),
                self::entry('riwayat impor', DocumentImport::query()->where('user_id', $id), fn (DocumentImport $import): string => $import->excel_name ?: '#'.$import->id),
            ],
            subjectName: 'user',
        );
    }

    /**
     * User aktif dinonaktifkan lewat UserDeactivator dengan `$data` sebagai pengganti per kelompok tugas.
     * User yang sudah nonaktif hanya diakhiri sesinya. WhatsApp yang masih antre untuk user ini dibatalkan.
     *
     * @param  User  $record
     *
     * @throws ValidationException bila ada tugas yang harus dialihkan tetapi tidak ada isian pengganti sama sekali (hapus massal).
     */
    public function beforeTrash(Model $record, User $actor, array $data): void
    {
        if ($record->isActive()) {
            $groups = $this->deactivator->requiredGroups($record);
            if ($groups !== [] && array_intersect($groups, array_keys($data)) === []) {
                throw ValidationException::withMessages(['record' => self::HAS_WORK_REASON]);
            }
        }

        $this->cancelQueuedWhatsApp($record);

        if ($record->isActive()) {
            $this->deactivator->deactivate($record, $actor, $data);

            return;
        }

        DB::table(config('session.table', 'sessions'))->where('user_id', $record->getKey())->delete();
    }

    /** User kembali dalam keadaan nonaktif; super admin mengaktifkannya lewat tombol Aktifkan. */
    public function afterRestore(Model $record, User $actor): void {}

    /**
     * @param  User  $record
     */
    public function purge(Model $record, User $actor): array
    {
        $id = $record->getKey();
        DocumentRequest::withTrashed()->where('reviewer_id', $id)->toBase()->update(['reviewer_id' => null]);
        DocumentRequest::withTrashed()->where('approver_id', $id)->toBase()->update(['approver_id' => null]);
        DocumentType::withTrashed()->where('request_approver_id', $id)->toBase()->update(['request_approver_id' => null]);
        $record->roles()->detach();
        $record->permissions()->detach();
        DB::table(config('session.table', 'sessions'))->where('user_id', $id)->delete();
        $record->forceDelete();

        return [];
    }

    /**
     * @param  User  $record
     */
    public function trashDenialReason(Model $record, User $actor): ?string
    {
        return $this->deactivator->deletionDenialReason($actor, $record);
    }

    /**
     * Pesan ke pengaju internal (tanpa nomor sendiri), ke PIC pengajuan, dan ke user ini secara langsung.
     */
    private function cancelQueuedWhatsApp(User $user): void
    {
        $requests = fn (string $column): Builder => DocumentRequest::withTrashed()->where($column, $user->getKey())->select('id');

        DocumentRequestNotification::whereIn('status', ['pending', 'failed'])
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query->where('recipient_kind', 'applicant')->whereIn('document_request_id', $requests('requester_id')->whereNull('requester_phone')))
                ->orWhere(fn (Builder $query): Builder => $query->where('recipient_kind', 'pic')->whereIn('document_request_id', $requests('pic_user_id')))
                ->orWhere('event_key', 'like', '%-u'.$user->getKey()))
            ->update(['status' => 'cancelled', 'error_message' => DocumentRequestNotification::RECIPIENT_TRASHED_MESSAGE]);
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
