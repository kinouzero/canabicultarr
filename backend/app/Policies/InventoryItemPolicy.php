<?php

namespace App\Policies;

use App\Enums\DelegatedPermission;
use App\Models\InventoryItem;
use App\Models\User;

class InventoryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, InventoryItem $item): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->hasOperationalPermission(DelegatedPermission::Inventory);
    }

    public function update(User $user, InventoryItem $item): bool
    {
        return $this->create($user);
    }
}
