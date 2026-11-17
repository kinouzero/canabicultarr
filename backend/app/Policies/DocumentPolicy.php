<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Strain;
use App\Models\User;
use App\Services\LocationAccess;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Document $entry): bool
    {
        return $user->is_active && app(LocationAccess::class)->record($user, $entry);
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Document $entry): bool
    {
        if ($entry->subject_kind === 'strain') {
            $strain = Strain::find($entry->subject_snapshot['id'] ?? null);
            if (! $strain || ! $user->can('update', $strain)) {
                return false;
            }
        }

        return $user->is_active && app(LocationAccess::class)->record($user, $entry) && ($user->isAdministrator() || (int) $entry->uploaded_by === (int) $user->getKey());
    }
}
