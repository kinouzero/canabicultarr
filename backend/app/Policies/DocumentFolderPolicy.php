<?php

namespace App\Policies;

use App\Models\DocumentFolder;
use App\Models\User;

class DocumentFolderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, DocumentFolder $folder): bool
    {
        return $user->is_active && ($user->isAdministrator() || $folder->created_by === $user->id);
    }

    public function delete(User $user, DocumentFolder $folder): bool
    {
        return $this->update($user, $folder);
    }
}
