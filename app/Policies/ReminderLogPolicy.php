<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReminderLog;
use App\Models\User;

class ReminderLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:ReminderLog');
    }

    public function view(User $user, ReminderLog $log): bool
    {
        return $user->can('View:ReminderLog');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReminderLog $log): bool
    {
        return false;
    }

    public function delete(User $user, ReminderLog $log): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ReminderLog $log): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ReminderLog $log): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, ReminderLog $log): bool
    {
        return false;
    }
}
