<?php

namespace App\Policies;

use App\Enums\DelegatedPermission;
use App\Models\Sensor;
use App\Models\User;
use App\Services\LocationAccess;

class SensorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Sensor $sensor): bool
    {
        return $user->is_active && app(LocationAccess::class)->record($user, $sensor);
    }

    public function create(User $user): bool
    {
        return $user->hasOperationalPermission(DelegatedPermission::Sensors);
    }

    public function update(User $user, Sensor $record): bool
    {
        return $this->create($user) && app(LocationAccess::class)->record($user, $record);
    }
}
