<?php

namespace App\Policies;

use App\Enums\DelegatedPermission;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationAccess;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Location $location): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->hasOperationalPermission(DelegatedPermission::Locations);
    }

    public function delete(User $user, Location $location): bool
    {
        return $this->update($user, $location);
    }

    public function update(User $user, Location $location): bool
    {
        return $this->create($user) && app(LocationAccess::class)->record($user, $location);
    }
}
