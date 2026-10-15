<?php

namespace App\Policies;

use App\Models\ReminderTemplate;
use App\Models\User;

class ReminderTemplatePolicy
{
    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:ReminderTemplate');
    }

    public function view(User $authUser, ReminderTemplate $record): bool
    {
        return $authUser->can('View:ReminderTemplate');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:ReminderTemplate') && ReminderTemplate::globalSetting() === null;
    }

    public function update(User $authUser, ReminderTemplate $record): bool
    {
        return $authUser->can('Update:ReminderTemplate');
    }

    public function delete(User $authUser, ReminderTemplate $record): bool
    {
        return false;
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
