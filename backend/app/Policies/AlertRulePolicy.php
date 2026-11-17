<?php

namespace App\Policies;

use App\Enums\DelegatedPermission;
use App\Models\AlertRule;
use App\Models\User;
use App\Services\LocationAccess;

class AlertRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, AlertRule $rule): bool
    {
        return $user->is_active && app(LocationAccess::class)->record($user, $rule);
    }

    public function create(User $user): bool
    {
        return $user->hasOperationalPermission(DelegatedPermission::Alerts);
    }

    public function update(User $user, AlertRule $rule): bool
    {
        return $this->create($user) && app(LocationAccess::class)->record($user, $rule);
    }
}
