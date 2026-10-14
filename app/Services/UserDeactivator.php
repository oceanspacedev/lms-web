<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menonaktifkan user (staf keluar) sekaligus mengalihkan seluruh tugasnya ke pengganti, atau mengaktifkannya kembali.
 * Peran dan izin tidak dicabut, supaya user bisa diaktifkan kembali utuh.
 */
class UserDeactivator
{
    /** Pengganti PIC dokumen: semua user aktif. */
    public const GROUP_DOCUMENT_PIC = 'document_pic';

    /** Pengganti PIC dan pemeriksa pengajuan, serta PIC dan pemeriksa pengajuan pada jenis dokumen. */
    public const GROUP_REVIEWER = 'reviewer';

    /** Pengganti penyetuju pengajuan dan penyetuju pengajuan pada jenis dokumen. */
    public const GROUP_APPROVER = 'approver';

    /** Nilai pilihan "Tim persetujuan": penyetuju dikosongkan sehingga siapa saja yang berhak bisa menyetujui. */
    public const APPROVAL_TEAM = 'team';

    /** Pengajuan yang sudah selesai atau ditolak tidak dialihkan. */
    public const CLOSED_REQUEST_STATUSES = ['archived', 'rejected'];

    public function __construct(private WaghubService $waghub) {}

    /**
     * Jumlah tugas user per data, untuk ringkasan di modal Nonaktifkan.
     *
     * @return array{documents: int, requests: int, document_types: int, drafts: int}
     */
    public function workload(User $user): array
    {
        return [
            'documents' => Document::withTrashed()->where('pic_user_id', $user->id)->count(),
            'requests' => $this->openRequests()->where(fn (Builder $query): Builder => $query->where('pic_user_id', $user->id)
                ->orWhere('reviewer_id', $user->id)->orWhere('approver_id', $user->id))->count(),
            'document_types' => DocumentType::where(fn (Builder $query): Builder => $query->where('request_pic_id', $user->id)
                ->orWhere('request_reviewer_id', $user->id)->orWhere('request_approver_id', $user->id))->count(),
            'drafts' => DocumentRequest::where('status', 'draft')->where('requester_id', $user->id)->count(),
        ];
    }

    /**
     * Kelompok tugas yang wajib diberi pengganti (jumlahnya lebih dari nol).
     *
     * @return list<string>
     */
    public function requiredGroups(User $user): array
    {
        $groups = [];
        if (Document::withTrashed()->where('pic_user_id', $user->id)->exists()) {
            $groups[] = self::GROUP_DOCUMENT_PIC;
        }
        if ($this->openRequests()->where(fn (Builder $query): Builder => $query->where('pic_user_id', $user->id)->orWhere('reviewer_id', $user->id))->exists()
            || DocumentType::where(fn (Builder $query): Builder => $query->where('request_pic_id', $user->id)->orWhere('request_reviewer_id', $user->id))->exists()) {
            $groups[] = self::GROUP_REVIEWER;
        }
        if ($this->openRequests()->where('approver_id', $user->id)->exists() || DocumentType::where('request_approver_id', $user->id)->exists()) {
            $groups[] = self::GROUP_APPROVER;
        }

        return $groups;
    }

    /**
     * User aktif selain target yang memenuhi syarat sebagai pengganti pada kelompok tugas ini.
     * Izin dicek lewat Gate, sama seperti policy pengajuan.
     *
     * @return Collection<int, User>
     */
    public function candidates(User $user, string $group): Collection
    {
        $users = User::active()->whereKeyNot($user->id)->orderBy('name')->get();

        return $users->filter(fn (User $candidate): bool => match ($group) {
            self::GROUP_REVIEWER => $candidate->can('Review:DocumentRequest')
                && (! $this->replacesRequestPicOfDocumentType($user) || $this->waghub->normalizePhone($candidate->phone) !== null),
            self::GROUP_APPROVER => $candidate->can('Approve:DocumentRequest'),
            default => true,
        })->values();
    }

    /**
     * Pilihan Select pengganti. Kelompok penyetuju mendapat opsi "Tim persetujuan" (nilai null pada pengajuan).
     *
     * @return array<int|string, string>
     */
    public function candidateOptions(User $user, string $group): array
    {
        $options = $this->candidates($user, $group)->pluck('name', 'id')->all();

        return $group === self::GROUP_APPROVER && $options !== []
            ? [self::APPROVAL_TEAM => 'Tim persetujuan (siapa saja yang berhak)'] + $options
            : $options;
    }

    /** Pesan penolakan bila ada kelompok tugas yang belum punya kandidat pengganti, atau null bila semua tersedia. */
    public function missingCandidatesMessage(User $user): ?string
    {
        foreach ($this->requiredGroups($user) as $group) {
            if ($this->candidates($user, $group)->isEmpty()) {
                return match ($group) {
                    self::GROUP_REVIEWER => 'Belum ada user aktif lain dengan izin "Periksa pengajuan"'
                        .($this->replacesRequestPicOfDocumentType($user) ? ' dan nomor WhatsApp valid' : '')
                        .' untuk menggantikan tugas pemeriksaan. Berikan izin tersebut lewat menu Hak Akses terlebih dahulu.',
                    self::GROUP_APPROVER => 'Belum ada user aktif lain dengan izin "Setujui pengajuan" untuk menggantikan tugas persetujuan. Berikan izin tersebut lewat menu Hak Akses terlebih dahulu.',
                    default => 'Belum ada user aktif lain untuk menggantikan PIC dokumen. Tambahkan user terlebih dahulu.',
                };
            }
        }

        return null;
    }

    /** Alasan aksi Nonaktifkan tidak boleh dijalankan, atau null bila boleh. */
    public function deactivationDenialReason(User $actor, User $user): ?string
    {
        return match (true) {
            ! $user->isActive() => 'User ini sudah nonaktif.',
            ! Gate::forUser($actor)->allows('update', $user) => 'Anda tidak berhak mengubah user.',
            $actor->is($user) => 'Anda tidak dapat menonaktifkan akun sendiri.',
            $user->isSuperAdmin() && ! $actor->isSuperAdmin() => 'Hanya super admin yang dapat menonaktifkan super admin.',
            $user->isSuperAdmin() && ! $this->hasOtherActiveSuperAdmin($user) => 'Super admin aktif terakhir tidak dapat dinonaktifkan.',
            default => null,
        };
    }

    /** Alasan aksi Aktifkan kembali tidak boleh dijalankan, atau null bila boleh. */
    public function reactivationDenialReason(User $actor, User $user): ?string
    {
        return match (true) {
            $user->isActive() => 'User ini sudah aktif.',
            ! Gate::forUser($actor)->allows('update', $user) => 'Anda tidak berhak mengubah user.',
            $user->isSuperAdmin() && ! $actor->isSuperAdmin() => 'Hanya super admin yang dapat mengaktifkan kembali super admin.',
            default => null,
        };
    }

    /**
     * Alihkan semua tugas user ke pengganti lalu nonaktifkan user, dalam satu transaksi.
     * Riwayat (history) pengajuan sengaja tidak ditambah, karena jumlahnya dipakai sebagai kunci notifikasi dan pengingat.
     *
     * @param  array<string, int|string|null>  $replacements  Kunci: document_pic, reviewer, approver (approver boleh "team").
     *
     * @throws ValidationException
     */
    public function deactivate(User $user, User $actor, array $replacements): void
    {
        if (($reason = $this->deactivationDenialReason($actor, $user)) !== null) {
            throw ValidationException::withMessages(['user' => $reason]);
        }

        DB::transaction(function () use ($user, $actor, $replacements): void {
            $resolved = $this->resolveReplacements($user, $replacements);

            $documents = Document::withTrashed()->where('pic_user_id', $user->id)->pluck('current_version_id', 'id');
            if ($documents->isNotEmpty()) {
                Document::withTrashed()->whereKey($documents->keys())->update(['pic_user_id' => $resolved[self::GROUP_DOCUMENT_PIC]]);
                foreach ($documents as $documentId => $versionId) {
                    DocumentActivity::create(['document_id' => $documentId, 'document_version_id' => $versionId, 'user_id' => $actor->id, 'event' => 'pic_reassigned']);
                }
                ReminderLog::whereIn('status', ['pending', 'failed'])
                    ->whereIn('document_version_id', DocumentVersion::whereIn('document_id', $documents->keys())->select('id'))
                    ->update(['request_payload' => null]);
            }

            $picRequestIds = DocumentRequest::where('pic_user_id', $user->id)->pluck('id');
            if (array_key_exists(self::GROUP_REVIEWER, $resolved)) {
                $this->openRequests()->where('pic_user_id', $user->id)->update(['pic_user_id' => $resolved[self::GROUP_REVIEWER]]);
                $this->openRequests()->where('reviewer_id', $user->id)->update(['reviewer_id' => $resolved[self::GROUP_REVIEWER]]);
                DocumentType::where('request_pic_id', $user->id)->update(['request_pic_id' => $resolved[self::GROUP_REVIEWER]]);
                DocumentType::where('request_reviewer_id', $user->id)->update(['request_reviewer_id' => $resolved[self::GROUP_REVIEWER]]);
            }
            if (array_key_exists(self::GROUP_APPROVER, $resolved)) {
                $this->openRequests()->where('approver_id', $user->id)->update(['approver_id' => $resolved[self::GROUP_APPROVER]]);
                DocumentType::where('request_approver_id', $user->id)->update(['request_approver_id' => $resolved[self::GROUP_APPROVER]]);
            }

            DocumentRequestNotification::whereIn('status', ['pending', 'failed'])
                ->where(fn (Builder $query): Builder => $query
                    ->where(fn (Builder $query): Builder => $query->where('recipient_kind', 'pic')->whereIn('document_request_id', $picRequestIds))
                    ->orWhere('event_key', 'like', '%-u'.$user->id))
                ->update(['status' => 'cancelled', 'error_message' => 'Penerima dinonaktifkan; tugasnya dialihkan ke user lain.']);

            $user->forceFill(['deactivated_at' => now(), 'deactivated_by' => $actor->id]);
            $user->setRememberToken(Str::random(60));
            $user->save();
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        });
    }

    /**
     * Aktifkan kembali user dengan peran dan izin semula. Ditolak bila nomor WhatsApp-nya sudah dipakai user aktif lain.
     *
     * @throws ValidationException
     */
    public function reactivate(User $user, User $actor): void
    {
        if (($reason = $this->reactivationDenialReason($actor, $user)) !== null) {
            throw ValidationException::withMessages(['user' => $reason]);
        }
        if (filled($user->phone) && User::phoneIsTaken($user->phone, $user->id)) {
            throw ValidationException::withMessages(['user' => 'Nomor WhatsApp user ini sudah dipakai user aktif lain. Ubah nomornya terlebih dahulu.']);
        }

        $user->forceFill(['deactivated_at' => null, 'deactivated_by' => null])->save();
    }

    /**
     * Cocokkan pilihan pengganti dengan kandidat yang sah saat ini.
     *
     * @param  array<string, int|string|null>  $replacements
     * @return array<string, int|null>
     *
     * @throws ValidationException
     */
    private function resolveReplacements(User $user, array $replacements): array
    {
        if (($message = $this->missingCandidatesMessage($user)) !== null) {
            throw ValidationException::withMessages(['user' => $message]);
        }

        $resolved = [];
        foreach ($this->requiredGroups($user) as $group) {
            $value = $replacements[$group] ?? null;
            if ($group === self::GROUP_APPROVER && $value === self::APPROVAL_TEAM) {
                $resolved[$group] = null;

                continue;
            }
            $candidate = filled($value) ? $this->candidates($user, $group)->firstWhere('id', (int) $value) : null;
            if ($candidate === null) {
                throw ValidationException::withMessages([$group => 'Pilih pengganti yang aktif dan memenuhi syarat.']);
            }
            $resolved[$group] = $candidate->id;
        }

        return $resolved;
    }

    private function replacesRequestPicOfDocumentType(User $user): bool
    {
        return DocumentType::where('request_pic_id', $user->id)->exists();
    }

    private function hasOtherActiveSuperAdmin(User $user): bool
    {
        return User::active()->role(config('filament-shield.super_admin.name'))->whereKeyNot($user->id)->exists();
    }

    /** @return Builder<DocumentRequest> */
    private function openRequests(): Builder
    {
        return DocumentRequest::whereNotIn('status', self::CLOSED_REQUEST_STATUSES);
    }
}
