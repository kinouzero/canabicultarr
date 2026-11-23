<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class AuthenticationSession
{
    public static function refresh(Request $request, User $user): void
    {
        $request->session()->regenerate();
        $request->session()->put([
            'auth_version' => $user->session_version,
            'auth_verified_at' => now()->timestamp,
        ]);
    }

    public static function recentlyVerified(Request $request): bool
    {
        $verifiedAt = $request->session()->get('auth_verified_at');

        return is_int($verifiedAt) && $verifiedAt > now()->subMinutes(10)->timestamp;
    }
}
