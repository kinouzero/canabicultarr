<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EnsureCurrentSession
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $version = (int) $request->session()->get('auth_version', 0);

        if (($user->email_activation_required && ! $user->email_verified_at) || ! $user->is_active || $version !== $user->session_version) {
            Log::notice('authentication.session.revoked', ['user_uuid' => $user->uuid]);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException;
        }

        $request->session()->put('auth_version', $version);

        $response = $next($request);
        if ($request->isMethod('GET')) {
            $current = User::find($user->id);
            if (! $current || ($current->email_activation_required && ! $current->email_verified_at) || ! $current->is_active || $current->session_version !== $version) {
                throw new AuthenticationException;
            }
        }

        return $response;
    }
}
