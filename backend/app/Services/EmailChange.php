<?php

namespace App\Services;

use App\Exceptions\AuthFlowException;
use App\Jobs\SendEmailChange;
use App\Mail\EmailChangeMail;
use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailChange
{
    private function authorize(User $actor, User $user): void
    {
        if (! $actor->is_active) {
            throw new AuthenticationException;
        }
        if ($actor->is($user)) {
            Gate::forUser($actor)->authorize('account.profile');
        } else {
            Gate::forUser($actor)->authorize('update', $user);
        }
    }

    public function pendingEmail(User $user): ?string
    {
        $user->loadMissing('pendingEmailChange.requester');
        $pending = $user->pendingEmailChange;

        return $pending && $this->current($pending, $user) ? $pending->email : null;
    }

    private function current(PendingEmailChange $pending, User $user): bool
    {
        return $pending->expires_at->isFuture() && $pending->user_version === $user->session_version
            && $pending->requester?->is_active && $pending->requester_version === $pending->requester->session_version;
    }

    /** Caller holds the target user lock; the current email is never assigned here. */
    public function request(User $actor, User $user, string $email, string $locale, bool $force = false): void
    {
        $this->authorize($actor, $user);
        $email = Str::lower(trim($email));
        if ($email === $user->email) {
            return;
        }
        $pending = PendingEmailChange::whereKey($user->id)->first();
        if (! $force && $pending?->email === $email && $pending->queued_at && $this->current($pending, $user)) {
            return;
        }
        if (! app(AccountActivation::class)->ready()) {
            throw new AuthFlowException('emailChange.unavailable', 503);
        }
        if (User::where('email', $email)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => ['users.validation.emailTaken']]);
        }
        PendingEmailChange::where('email', $email)->where('expires_at', '<=', now())->delete();
        if (PendingEmailChange::where('email', $email)->where('user_id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => ['users.validation.emailTaken']]);
        }
        if (! RateLimiter::attempt('email-change:'.$user->uuid, 1, fn () => true, 60)) {
            throw new AuthFlowException('emailChange.rateLimited', 429);
        }
        $token = Str::random(64);
        try {
            PendingEmailChange::updateOrCreate(['user_id' => $user->id], ['requested_by' => $actor->id,
                'email' => $email, 'token_hash' => hash('sha256', $token), 'user_version' => $user->session_version,
                'requester_version' => $actor->session_version, 'expires_at' => now()->addHour(), 'queued_at' => null]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['users.validation.emailTaken']]);
        }
        DB::table('account_activation_tokens')->where('user_id', $user->id)->delete();
        app(AccountSecurity::class)->clearResetTokens([$user->email, $email]);
        $user->unsetRelation('pendingEmailChange');
        $dispatch = function () use ($user, $email, $token, $locale): void {
            try {
                Queue::connection()->push(new SendEmailChange($user->uuid, $email, $token, $locale), '', 'account-activation');
                PendingEmailChange::whereKey($user->id)->where('token_hash', hash('sha256', $token))->update(['queued_at' => now()]);
            } catch (\Throwable) {
                Log::warning('account.email_change_queue_failed', ['user_uuid' => $user->uuid]);
                throw new AuthFlowException('emailChange.unavailable', 503);
            }
        };
        if (config('queue.connections.'.config('queue.default').'.driver') === 'redis' && DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
        Log::notice('account.email_change_requested', ['actor_uuid' => $actor->uuid, 'user_uuid' => $user->uuid]);
    }

    public function manage(User $actor, User $target, bool $resend, string $locale = 'en'): User
    {
        return DB::transaction(function () use ($actor, $target, $resend, $locale) {
            $users = User::whereIn('id', [$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $users->get($actor->id);
            $user = $users->get($target->id);
            if (! $current || ! $user || $current->session_version !== $actor->session_version) {
                throw new AuthenticationException;
            }
            $this->authorize($current, $user);
            if ($resend) {
                $pending = PendingEmailChange::whereKey($user->id)->first();
                if (! $pending || ! $this->current($pending, $user)) {
                    throw new AuthFlowException('emailChange.noPending', 409);
                }
                $this->request($current, $user, $pending->email, $locale, true);
            } else {
                PendingEmailChange::whereKey($user->id)->delete();
                Log::notice('account.email_change_canceled', ['actor_uuid' => $current->uuid, 'user_uuid' => $user->uuid]);
            }
            $user->unsetRelation('pendingEmailChange');

            return $user;
        });
    }

    public function confirm(string $email, string $token): void
    {
        try {
            DB::transaction(function () use ($email, $token) {
                $hint = PendingEmailChange::where('email', $email)->first();
                if (! $hint) {
                    throw new AuthFlowException('emailChange.invalidLink', 422);
                }
                $users = User::whereIn('id', [$hint->user_id, $hint->requested_by])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $pending = PendingEmailChange::whereKey($hint->user_id)->lockForUpdate()->first();
                $user = $users->get($hint->user_id);
                $issuer = $users->get($hint->requested_by);
                $pending?->setRelation('requester', $issuer);
                if (! $pending || ! $user || ! $issuer || $pending->requested_by !== $hint->requested_by || $pending->email !== $email || ! $this->current($pending, $user)
                    || ! hash_equals($pending->token_hash, hash('sha256', $token))) {
                    throw new AuthFlowException('emailChange.invalidLink', 422);
                }
                $this->authorize($issuer, $user);
                $old = $user->email;
                $user->forceFill(['email' => $email, 'email_verified_at' => now(), 'email_activation_required' => false])->save();
                app(AccountSecurity::class)->clearResetTokens([$old, $email]);
                app(AccountSecurity::class)->invalidate($user);
                DB::table('account_activation_tokens')->where('user_id', $user->id)->delete();
                PendingEmailChange::whereKey($user->id)->delete();
                Log::notice('account.email_changed', ['actor_uuid' => $issuer->uuid, 'user_uuid' => $user->uuid]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new AuthFlowException('emailChange.emailTaken', 422);
        }
    }

    public function deliver(string $uuid, string $email, string $token, string $locale): void
    {
        $user = User::where('uuid', $uuid)->first();
        $pending = $user ? PendingEmailChange::with('requester')->whereKey($user->id)->first() : null;
        if (! $pending || ! $this->current($pending, $user) || $pending->email !== $email
            || ! hash_equals($pending->token_hash, hash('sha256', $token)) || ! app(AccountActivation::class)->ready()) {
            return;
        }
        try {
            $this->authorize($pending->requester, $user);
            $url = rtrim((string) config('app.url'), '/').'/#confirm-email='.$token;
            Mail::to($email)->send(new EmailChangeMail($url, $locale));
        } catch (\Throwable) {
            Log::warning('account.email_change_delivery_failed', ['user_uuid' => $uuid]);
        }
    }
}
