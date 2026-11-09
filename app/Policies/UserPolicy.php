<?php

namespace App\Policies;

use App\Models\User;
use App\Services\UserDeactivator;

class UserPolicy
{
    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:User');
    }

    public function view(User $authUser, User $user): bool
    {
        return $authUser->can('View:User');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:User');
    }

    public function update(User $authUser, User $user): bool
    {
        return $authUser->can('Update:User');
    }

    /** Izin hapus, ditambah larangan nonaktifkan yang sama (diri sendiri, super admin) lewat UserDeactivator. */
    public function delete(User $authUser, User $user): bool
    {
        return $authUser->can('Delete:User') && app(UserDeactivator::class)->deletionDenialReason($authUser, $user) === null;
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:User');
    }

    /** Hanya super admin; izin Restore:* tidak membukanya. */
    public function restore(User $authUser, User $user): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function restoreAny(User $authUser): bool
    {
        return $authUser->isSuperAdmin();
    }

    /** Hanya super admin; izin ForceDelete:* tidak membukanya. */
    public function forceDelete(User $authUser, User $user): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return $authUser->isSuperAdmin();
    }
}
