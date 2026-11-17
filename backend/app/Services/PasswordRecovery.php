<?php

namespace App\Services;

use App\Exceptions\AuthFlowException;
use App\Jobs\SendPasswordRecovery;
use App\Mail\PasswordRecoveryMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

class PasswordRecovery
{
    public function ready(): bool
    {
        $url = (string) config('password_recovery.url', '');
        $parts = parse_url($url);
        $valid = filter_var($url, FILTER_VALIDATE_URL) !== false && strlen($url) <= 2048
            && ! preg_match('/[\x00-\x20\x7f]/', $url) && is_array($parts)
            && ! array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))
            && in_array($parts['path'] ?? '/', ['', '/'], true)
            && (($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)));

        $broker = config('auth.defaults.passwords', 'users');
        $atomic = config('auth.passwords.'.$broker.'.driver', 'database') === 'database'
            && in_array(config('auth.passwords.'.$broker.'.connection'), [null, config('database.default')], true);

        return (bool) config('auth.local_enabled') && $atomic && AccountMailQueue::ready() && (bool) config('password_recovery.enabled') && $valid && app(NotificationConfiguration::class)->ready('email');
    }

    private function ensureReady(): void
    {
        if (! $this->ready()) {
            throw new AuthFlowException('passwordRecovery.unavailable', 503);
        }
    }

    public function request(string $email, string $locale): void
    {
        $this->ensureReady();
        // Same silent per-address limit for missing, inactive and eligible accounts.
        $key = 'password-recovery:'.hash_hmac('sha256', $email, (string) config('app.key'));
        if (! RateLimiter::attempt($key, 1, fn () => true, 60)) {
            return;
        }
        try {
            $version = User::where('email', $email)->value('session_version');
            Queue::connection()->push(new SendPasswordRecovery($email, $locale, $version, now()->timestamp), '', 'password-recovery');
        } catch (\Throwable) {
            Log::warning('account.password_recovery_queue_failed');
        }
    }

    public function deliver(string $email, string $locale, ?int $version, int $requestedAt): void
    {
        if (! $this->ready() || $requestedAt < now()->timestamp - 300 || $version === null) {
            return;
        }
        try {
            DB::transaction(function () use ($email, $locale, $version) {
                $user = User::where('email', $email)->lockForUpdate()->first();
                if (! $user || ! $user->is_active || $user->session_version !== $version || ! filled($user->password)) {
                    return;
                }
                $repository = Password::broker()->getRepository();
                if ($repository->recentlyCreatedToken($user)) {
                    return;
                }
                $token = $repository->create($user);
                $url = rtrim((string) config('password_recovery.url'), '/').'/'.'#reset-password='.$token;
                $broker = config('auth.defaults.passwords', 'users');
                Mail::to($user->email)->send(new PasswordRecoveryMail($url, (int) config('auth.passwords.'.$broker.'.expire', 60), $locale));
            });
        } catch (\Throwable) {
            // Do not report transport exceptions: they may contain recipients, credentials or tokens.
            Log::warning('account.password_recovery_delivery_failed');
        }
    }

    public function reset(string $email, string $token, string $password): void
    {
        $this->ensureReady();
        DB::transaction(function () use ($email, $token, $password) {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if (! $user || ! $user->is_active || ! filled($user->password) || ! Password::broker()->tokenExists($user, $token)) {
                throw new AuthFlowException('passwordRecovery.invalidLink', 422);
            }
            if (Hash::check($password, $user->password)) {
                throw new AuthFlowException('passwordRecovery.passwordUnchanged', 422);
            }
            $user->password = Hash::make($password);
            app(AccountSecurity::class)->invalidate($user);
            Log::notice('account.password_recovered', ['actor_uuid' => $user->uuid]);
        });
    }
}
