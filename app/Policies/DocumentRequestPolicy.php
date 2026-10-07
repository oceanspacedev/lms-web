<?php

namespace App\Policies;

use App\Models\DocumentRequest;
use App\Models\User;

class DocumentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:DocumentRequest');
    }

    public function view(User $user, DocumentRequest $request): bool
    {
        return $user->can('View:DocumentRequest') && ($request->requester_id === $user->id || $user->can('ViewAll:DocumentRequest') || ($request->status !== 'draft' && ($request->pic_user_id === $user->id ||
            ($user->can('Review:DocumentRequest') && ($request->reviewer_id === null || $request->reviewer_id === $user->id)) ||
            ($user->can('Approve:DocumentRequest') && ($request->approver_id === null || $request->approver_id === $user->id))
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

    public function review(User $user, DocumentRequest $request): bool
    {
        return $user->can('Review:DocumentRequest') && $request->status === 'submitted' && $request->requester_id !== $user->id && ($request->reviewer_id === null || $request->reviewer_id === $user->id);
    }

    public function approve(User $user, DocumentRequest $request): bool
    {
        return $user->can('Approve:DocumentRequest') && $request->status === 'review' && $request->requester_id !== $user->id && ($request->approver_id === null || $request->approver_id === $user->id);
    }

    public function decide(User $user, DocumentRequest $request): bool
    {
        return $this->review($user, $request) || $this->approve($user, $request);
    }

    public function archive(User $user, DocumentRequest $request): bool
    {
        return $request->status === 'approved' && $user->can('Create:Document') && ($request->requester_id === $user->id || $user->can('Approve:DocumentRequest'));
    }

    public function delete(User $user, DocumentRequest $request): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
