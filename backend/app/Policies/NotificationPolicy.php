<?php

namespace App\Policies;

use App\Models\User;

class NotificationPolicy
{
    public function view(User $user): bool
    {
        return $user->is_active;
    }

    public function manage(User $user): bool
    {
        return $user->is_active && $user->isAdministrator();
    }
}
