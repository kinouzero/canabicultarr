<?php

namespace App\Policies;

use App\Models\User;

class AccountSecurityPolicy
{
    public function manage(User $user): bool
    {
        return $user->is_active;
    }
}
