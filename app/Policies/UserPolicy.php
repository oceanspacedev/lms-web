<?php

namespace App\Policies;

use App\Models\User;

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

    public function delete(User $authUser, User $user): bool
    {
        return false;
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
