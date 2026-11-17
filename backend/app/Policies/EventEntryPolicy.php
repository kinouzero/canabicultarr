<?php

namespace App\Policies;

use App\Models\EventEntry;
use App\Models\User;
use App\Services\LocationAccess;

class EventEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, EventEntry $entry): bool
    {
        return $user->is_active && app(LocationAccess::class)->record($user, $entry);
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, EventEntry $entry): bool
    {
        return $user->is_active && app(LocationAccess::class)->record($user, $entry) && ($user->isAdministrator() || (int) $entry->created_by === (int) $user->getKey());
    }
}
