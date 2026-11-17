<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountSecurity
{
    private function actor(User $actor, bool $lock = false): User
    {
        $query = User::whereKey($actor->getKey());
        $current = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }
        Gate::forUser($current)->authorize('account.security');

        return $current;
    }

    public function status(User $actor, string $sessionId): array
    {
        $current = $this->actor($actor);
        $connection = config('session.connection');
        $table = config('session.table', 'sessions');
        $tracked = config('session.driver') === 'database' && Schema::connection($connection)->hasTable($table);
        $others = $tracked ? DB::connection($connection)->table($table)->where('user_id', $current->id)->where('id', '<>', $sessionId)->where('last_activity', '>=', now()->timestamp - max(1, (int) config('session.lifetime', 120)) * 60)->count() : null;
        $this->actor($actor);

        return ['localPasswordAvailable' => filled($current->password), 'databaseSessions' => $tracked, 'otherSessions' => $others];
    }

    public function apply(User $actor, string $currentPassword, ?string $newPassword = null): void
    {
        DB::transaction(function () use ($actor, $currentPassword, $newPassword) {
            $current = $this->actor($actor, true);
            if (! filled($current->password)) {
                throw ValidationException::withMessages(['current_password' => ['accountSecurity.errors.localPasswordUnavailable']]);
            }
            if (! Hash::check($currentPassword, $current->password)) {
                throw ValidationException::withMessages(['current_password' => ['accountSecurity.errors.currentPasswordInvalid']]);
            }
            if ($newPassword !== null) {
                if (Hash::check($newPassword, $current->password)) {
                    throw ValidationException::withMessages(['password' => ['accountSecurity.errors.passwordUnchanged']]);
                }
                $current->password = Hash::make($newPassword);
            }
            $this->invalidate($current);
        });
        Log::notice($newPassword === null ? 'account.sessions_revoked' : 'account.password_changed', ['actor_uuid' => $actor->uuid]);
    }

    /** Caller must hold the user row lock inside a transaction. */
    public function invalidate(User $current): void
    {
        $current->session_version++;
        $current->remember_token = null;
        $current->save();
        if (config('session.driver') === 'database' && Schema::connection(config('session.connection'))->hasTable(config('session.table', 'sessions'))) {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $current->id)->delete();
        }
        if (Schema::hasTable('push_subscriptions')) {
            PushSubscription::where('user_id', $current->id)->delete();
        }
        DB::table('pending_email_changes')->where('user_id', $current->id)->orWhere('requested_by', $current->id)->delete();
        $current->unsetRelation('pendingEmailChange');
        $this->clearResetTokens([$current->email]);
    }

    public function clearResetTokens(array $emails): void
    {
        $broker = config('auth.defaults.passwords', 'users');
        $connection = config('auth.passwords.'.$broker.'.connection');
        $table = config('auth.passwords.'.$broker.'.table', 'password_reset_tokens');
        if (is_string($table) && $table !== '' && Schema::connection($connection)->hasTable($table)) {
            DB::connection($connection)->table($table)->whereIn('email', array_unique($emails))->delete();
        }
    }
}
