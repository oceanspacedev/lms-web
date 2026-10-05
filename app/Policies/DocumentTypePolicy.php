<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DocumentTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DocumentType');
    }

    public function view(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('View:DocumentType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DocumentType');
    }

    public function update(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('Update:DocumentType');
    }

    public function delete(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('Delete:DocumentType');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DocumentType');
    }

    public function restore(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('Restore:DocumentType');
    }

    public function forceDelete(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('ForceDelete:DocumentType');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DocumentType');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DocumentType');
    }

    public function replicate(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('Replicate:DocumentType');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DocumentType');
    }
}
