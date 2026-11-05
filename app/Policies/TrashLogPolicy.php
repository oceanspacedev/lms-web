<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TrashLog;
use App\Models\User;

/**
 * Riwayat penghapusan hanya bisa dibaca super admin dan tidak pernah diubah (spec §3.5).
 */
class TrashLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, TrashLog $log): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TrashLog $log): bool
    {
        return false;
    }

    public function delete(User $user, TrashLog $log): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
