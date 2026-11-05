<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DocumentTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:DocumentType');
    }

    public function view(User $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('View:DocumentType');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:DocumentType');
    }

    public function update(User $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('Update:DocumentType');
    }

    public function delete(User $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('Delete:DocumentType');
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:DocumentType');
    }

    /** Hanya super admin; izin Restore:* tidak membukanya. */
    public function restore(User $authUser, DocumentType $documentType): bool
    {
        return $authUser->isSuperAdmin();
    }

    /** Hanya super admin; izin ForceDelete:* tidak membukanya. */
    public function forceDelete(User $authUser, DocumentType $documentType): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function restoreAny(User $authUser): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function replicate(User $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('Replicate:DocumentType');
    }

    public function reorder(User $authUser): bool
    {
        return $authUser->can('Reorder:DocumentType');
    }
}
