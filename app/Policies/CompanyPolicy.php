<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CompanyPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Company');
    }

    public function view(User $authUser, Company $company): bool
    {
        return $authUser->can('View:Company');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Company');
    }

    public function update(User $authUser, Company $company): bool
    {
        return $authUser->can('Update:Company');
    }

    public function delete(User $authUser, Company $company): bool
    {
        return $authUser->can('Delete:Company');
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:Company');
    }

    /** Hanya super admin; izin Restore:* tidak membukanya. */
    public function restore(User $authUser, Company $company): bool
    {
        return $authUser->isSuperAdmin();
    }

    /** Hanya super admin; izin ForceDelete:* tidak membukanya. */
    public function forceDelete(User $authUser, Company $company): bool
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

    public function replicate(User $authUser, Company $company): bool
    {
        return $authUser->can('Replicate:Company');
    }

    public function reorder(User $authUser): bool
    {
        return $authUser->can('Reorder:Company');
    }
}
