<?php

namespace App\Policies;

use App\Enums\DelegatedPermission;
use App\Models\Plant;
use App\Models\User;
use App\Services\LocationAccess;

class PlantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Plant $plant): bool
    {
        return $user->is_active && app(LocationAccess::class)->record($user, $plant);
    }

    public function create(User $user): bool
    {
        return $user->hasOperationalPermission(DelegatedPermission::Plants);
    }

    public function delete(User $user, Plant $record): bool
    {
        return $this->update($user, $record);
    }

    public function update(User $user, Plant $plant): bool
    {
        return $this->create($user) && $this->view($user, $plant);
    }
}
