<?php

namespace App\Policies;

use App\Models\DocumentRequest;
use App\Models\User;

/**
 * Staff Legal = pemegang `Review:DocumentRequest`. Pemeriksaan (draf pertama, revisi, tolak) mengikuti pemeriksa yang ditunjuk,
 * kecuali super admin yang boleh mengambil alih; setelah draf keluar, semua Staff Legal boleh bertindak.
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

    /** Tandai pengajuan mulai diperiksa (Periksa): pemeriksa yang ditunjuk mengambil pengajuan yang baru masuk. */
    public function startReview(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && $request->status === 'submitted' && $this->isAssignedReviewer($user, $request);
    }

    /** Tandai draft sedang disusun (Proses Drafting): pemeriksa yang ditunjuk, dari pengajuan yang menunggu atau sedang diperiksa. */
    public function startDrafting(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && in_array($request->status, ['submitted', 'reviewing'], true) && $this->isAssignedReviewer($user, $request);
    }

    /** Revisi atau tolak pengajuan yang menunggu diperiksa, sedang diperiksa, atau sedang di-drafting. */
    public function revise(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && in_array($request->status, DocumentRequest::REVIEW_STATUSES, true) && $this->isAssignedReviewer($user, $request);
    }

    /** Keluarkan draf pertama (pemeriksa yang ditunjuk), atau ganti draf selama soft file bertanda tangan belum diunggah (semua Staff Legal). */
    public function issueDraft(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && (
            (in_array($request->status, DocumentRequest::REVIEW_STATUSES, true) && $this->isAssignedReviewer($user, $request)) ||
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

    /** Fisik diterima: semua Staff Legal yang boleh mengarsipkan dokumen. */
    public function markReceived(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && $user->can('Create:Document') && in_array($request->status, ['signing', 'handover'], true);
    }

    /** Unggah scan dokumen fisik dan selesaikan pengajuan (tahap Scanning); scan masuk arsip Dokumen. */
    public function completeWithScan(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && $user->can('Create:Document') && $request->status === 'scanning';
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

    /** Bukan pengajuan sendiri, dan pemeriksa belum ditunjuk, ditunjuk ke user ini, atau user ini super admin. */
    private function isAssignedReviewer(User $user, DocumentRequest $request): bool
    {
        return $request->requester_id !== $user->id && ($request->reviewer_id === null || $request->reviewer_id === $user->id || $user->isSuperAdmin());
    }
}
