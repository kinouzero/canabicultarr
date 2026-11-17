<?php

namespace App\Policies;

use App\Enums\DelegatedPermission;
use App\Models\Strain;
use App\Models\User;

class StrainPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Strain $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasOperationalPermission(DelegatedPermission::Strains);
    }

    public function delete(User $user, Strain $record): bool
    {
        return $this->update($user, $record);
    }

    public function update(User $user, Strain $record): bool
    {
        return $this->create($user);
    }
}
