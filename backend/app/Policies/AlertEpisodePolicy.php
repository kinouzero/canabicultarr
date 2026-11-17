<?php

namespace App\Policies;

use App\Models\AlertEpisode;
use App\Models\User;
use App\Services\LocationAccess;

class AlertEpisodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, AlertEpisode $episode): bool
    {
        return $user->is_active && app(LocationAccess::class)->record($user, $episode);
    }

    public function acknowledge(User $user, AlertEpisode $episode): bool
    {
        return $user->is_active && app(LocationAccess::class)->record($user, $episode);
    }
}
