<?php

namespace App\Policies;

use App\Enums\DelegatedPermission;
use App\Models\Asset;
use App\Models\User;
use App\Services\LocationAccess;

class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Asset $asset): bool
    {
        return $user->is_active && app(LocationAccess::class)->record($user, $asset);
    }

    public function create(User $user): bool
    {
        return $user->hasOperationalPermission(DelegatedPermission::Assets);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->create($user) && app(LocationAccess::class)->record($user, $asset);
    }
}
