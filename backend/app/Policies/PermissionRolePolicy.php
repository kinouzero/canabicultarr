<?php

namespace App\Policies;

use App\Models\PermissionRole;
use App\Models\User;

class PermissionRolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isAdministrator();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, PermissionRole $role): bool
    {
        return $this->viewAny($user);
    }
}
