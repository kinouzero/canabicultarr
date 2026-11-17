<?php

namespace App\Services;

use App\Exceptions\AuthFlowException;
use App\Jobs\SendAccountActivation;
use App\Mail\AccountActivationMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AccountActivation
{
    public function ready(): bool
    {
        $url = (string) config('app.url');
        $parts = parse_url($url);

        return app(NotificationConfiguration::class)->ready('email')
            && AccountMailQueue::ready()
            && filter_var($url, FILTER_VALIDATE_URL) !== false && strlen($url) <= 2048
            && ! preg_match('/[\x00-\x20\x7f]/', $url) && is_array($parts)
            && ! array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))
            && in_array($parts['path'] ?? '/', ['', '/'], true)
            && (($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http'
                && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)));
    }

    public function begin(User $user, string $locale): void
    {
        if (! $this->ready()) {
            throw new AuthFlowException('accountActivation.unavailable', 503);
        }
        // Redis dispatch waits for the caller's transaction to commit.
        $token = Str::random(64);
        DB::table('account_activation_tokens')->updateOrInsert(['user_id' => $user->id], [
            'email' => $user->email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(),
        ]);
        $dispatch = function () use ($user, $token, $locale): void {
            try {
                Queue::connection()->push(new SendAccountActivation($user->uuid, $user->email, $token, $locale), '', 'account-activation');
            } catch (\Throwable) {
                Log::warning('account.activation_queue_failed');
                throw new AuthFlowException('accountActivation.unavailable', 503);
            }
        };
        if (config('queue.connections.'.config('queue.default').'.driver') === 'redis' && DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }

    public function resend(string $email, string $locale): void
    {
        if (! $this->ready()) {
            throw new AuthFlowException('accountActivation.unavailable', 503);
        }
        $key = 'account-activation:'.hash_hmac('sha256', $email, (string) config('app.key'));
        if (! RateLimiter::attempt($key, 1, fn () => true, 60)) {
            return;
        }
        DB::transaction(function () use ($email, $locale) {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if ($user && $user->is_active && $user->email_activation_required && ! $user->email_verified_at) {
                $this->begin($user, $locale);
            }
        });
    }

    public function deliver(string $uuid, string $email, string $token, string $locale): void
    {
        if (! $this->ready()) {
            return;
        }
        $user = User::where('uuid', $uuid)->first();
        if (! $user || ! $user->is_active || ! $user->email_activation_required || $user->email_verified_at || $user->email !== $email) {
            return;
        }
        $record = DB::table('account_activation_tokens')->where('user_id', $user->id)->where('email', $email)
            ->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        if (! $record) {
            return;
        }
        try {
            $url = rtrim((string) config('app.url'), '/').'/#activate-account='.$token;
            Mail::to($email)->send(new AccountActivationMail($url, $locale));
        } catch (\Throwable) {
            // Transport exceptions may include credentials or activation links.
            Log::warning('account.activation_delivery_failed');
        }
    }

    public function confirm(string $email, string $token): void
    {
        DB::transaction(function () use ($email, $token) {
            $user = User::where('email', $email)->lockForUpdate()->first();
            $record = $user ? DB::table('account_activation_tokens')->where('user_id', $user->id)->first() : null;
            if (! $user || ! $user->is_active || ! $user->email_activation_required || $user->email_verified_at
                || ! $record || $record->email !== $user->email || $record->expires_at <= now()->toDateTimeString()
                || ! hash_equals($record->token_hash, hash('sha256', $token))) {
                throw new AuthFlowException('accountActivation.invalidLink', 422);
            }
            $user->forceFill(['email_verified_at' => now(), 'email_activation_required' => false])->save();
            DB::table('account_activation_tokens')->where('user_id', $user->id)->delete();
            Log::notice('account.activated', ['user_uuid' => $user->uuid]);
        });
    }
}
