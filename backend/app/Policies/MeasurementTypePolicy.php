<?php

namespace App\Policies;

use App\Enums\DelegatedPermission;
use App\Models\MeasurementType;
use App\Models\User;
use App\Services\LocationAccess;

class MeasurementTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->hasOperationalPermission(DelegatedPermission::Sensors) && ! app(LocationAccess::class)->restricted($user);
    }

    public function update(User $user, MeasurementType $record): bool
    {
        return $this->create($user);
    }
}
