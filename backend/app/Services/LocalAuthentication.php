<?php

namespace App\Services;

use App\Exceptions\AuthFlowException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LocalAuthentication
{
    public function login(array $credentials, Request $request): void
    {
        if (! config('auth.local_enabled')) {
            throw new AuthFlowException('auth.errors.localDisabled', 403);
        }
        $key = 'local-login:'.hash('sha256', $credentials['email'].'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new AuthFlowException('auth.errors.rateLimited', 429);
        }

        $authenticated = DB::transaction(function () use ($credentials) {
            // Keep credential verification/rehashing consistent with administrator updates.
            $user = User::where('email', $credentials['email'])->lockForUpdate()->first();
            if ($user && $user->is_active && $user->email_activation_required && ! $user->email_verified_at
                && Hash::check($credentials['password'], $user->password)) {
                throw new AuthFlowException('accountActivation.required', 403);
            }

            return Auth::attempt([...$credentials, 'is_active' => true]);
        });
        if (! $authenticated) {
            RateLimiter::hit($key, 60);
            Log::notice('authentication.login.failed', ['ip' => $request->ip()]);
            throw new AuthFlowException('auth.errors.invalidCredentials', 422);
        }

        RateLimiter::clear($key);
        AuthenticationSession::refresh($request, $request->user());
        Log::notice('authentication.login.succeeded', ['user_uuid' => $request->user()->uuid]);
    }

    public function register(array $data, Request $request): void
    {
        if (! config('auth.local_enabled') || ! config('auth.registration_enabled')) {
            throw new AuthFlowException('auth.errors.registrationDisabled', 403);
        }
        if (app(AdministratorSetup::class)->isRequired()) {
            throw new AuthFlowException('auth.errors.setupRequired', 409);
        }
        try {
            DB::transaction(function () use ($data) {
                $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
                $user->email_activation_required = true;
                $user->save();
                app(AccountActivation::class)->begin($user, $data['locale'] ?? 'en');
                Log::notice('authentication.registered', ['user_uuid' => $user->uuid]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['auth.validation.emailTaken']]);
        }

    }

    public function logout(Request $request): void
    {
        $uuid = $request->user()->uuid;
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Log::notice('authentication.logout', ['user_uuid' => $uuid]);
    }
}
