<?php

namespace App\Policies;

use App\Models\DocumentRequest;
use App\Models\User;

/**
 * Staff Legal = pemegang `Review:DocumentRequest`. Pemeriksaan (draf pertama, revisi, tolak) mengikuti pemeriksa yang ditunjuk;
 * setelah draf keluar, semua Staff Legal boleh bertindak.
 */
class DocumentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:DocumentRequest');
    }

    /** Pengajuan di tempat sampah hanya untuk super admin (baca-saja). */
    public function view(User $user, DocumentRequest $request): bool
    {
        if ($request->trashed() && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->can('View:DocumentRequest') && ($request->requester_id === $user->id || $user->can('ViewAll:DocumentRequest') || ($request->status !== 'draft' && ($request->pic_user_id === $user->id ||
            ($user->can('Review:DocumentRequest') && ($request->reviewer_id === null || $request->reviewer_id === $user->id || in_array($request->status, DocumentRequest::LEGAL_VISIBLE_STATUSES, true)))
        )));
    }

    public function create(User $user): bool
    {
        return $user->can('Create:DocumentRequest');
    }

    public function update(User $user, DocumentRequest $request): bool
    {
        return $user->can('Update:DocumentRequest') && $request->editableBy($user);
    }

    public function submit(User $user, DocumentRequest $request): bool
    {
        return $this->update($user, $request);
    }

    /** Revisi atau tolak pengajuan yang sedang diperiksa. */
    public function revise(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && $request->status === 'submitted' && $this->isAssignedReviewer($user, $request);
    }

    /** Keluarkan draf pertama (pemeriksa yang ditunjuk), atau ganti draf selama scan belum diunggah (semua Staff Legal). */
    public function issueDraft(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && (
            ($request->status === 'submitted' && $this->isAssignedReviewer($user, $request)) ||
            ($request->status === 'signing' && $request->signed_path === null)
        );
    }

    public function uploadSigned(User $user, DocumentRequest $request): bool
    {
        return in_array($request->status, ['signing', 'handover'], true) && ($request->requester_id === $user->id || $user->can('Review:DocumentRequest'));
    }

    /** Pengaju menulis alasannya sendiri; Staff Legal boleh mencatat alasan yang disampaikan pengaju lewat jalur lain. */
    public function addLateReason(User $user, DocumentRequest $request): bool
    {
        return ($request->requester_id === $user->id || $user->can('Review:DocumentRequest')) && $request->isHandoverLate();
    }

    public function markReceived(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && $user->can('Create:Document') && in_array($request->status, ['signing', 'handover'], true);
    }

    public function cancel(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && in_array($request->status, DocumentRequest::CANCELLABLE_STATUSES, true);
    }

    public function delete(User $user, DocumentRequest $request): bool
    {
        return $user->can('Delete:DocumentRequest');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:DocumentRequest');
    }

    /** Hanya super admin; izin Restore:* tidak membukanya. */
    public function restore(User $user, DocumentRequest $request): bool
    {
        return $user->isSuperAdmin();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /** Hanya super admin; izin ForceDelete:* tidak membukanya. */
    public function forceDelete(User $user, DocumentRequest $request): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /** Bukan pengajuan sendiri, dan pemeriksa belum ditunjuk atau ditunjuk ke user ini. */
    private function isAssignedReviewer(User $user, DocumentRequest $request): bool
    {
        return $request->requester_id !== $user->id && ($request->reviewer_id === null || $request->reviewer_id === $user->id);
    }
}
