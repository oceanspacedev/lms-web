<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DocumentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Document');
    }

    public function view(User $authUser, Document $document): bool
    {
        return $authUser->can('View:Document');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Document');
    }

    public function update(User $authUser, Document $document): bool
    {
        return $authUser->can('Update:Document');
    }

    public function delete(User $authUser, Document $document): bool
    {
        return $authUser->can('Delete:Document');
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:Document');
    }

    /** Hanya super admin; izin Restore:* tidak membukanya. */
    public function restore(User $authUser, Document $document): bool
    {
        return $authUser->isSuperAdmin();
    }

    /** Hanya super admin; izin ForceDelete:* tidak membukanya. */
    public function forceDelete(User $authUser, Document $document): bool
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

    public function replicate(User $authUser, Document $document): bool
    {
        return $authUser->can('Replicate:Document');
    }

    public function reorder(User $authUser): bool
    {
        return $authUser->can('Reorder:Document');
    }
}
