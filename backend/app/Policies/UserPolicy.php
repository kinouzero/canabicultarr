<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAdministration(User $user): bool
    {
        return $user->is_active && $user->isAdministrator();
    }

    public function viewAny(User $user): bool
    {
        return $this->viewAdministration($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAdministration($user);
    }

    public function update(User $user, User $target): bool
    {
        return $this->viewAdministration($user);
    }
}
