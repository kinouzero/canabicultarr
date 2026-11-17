<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AccountProfile
{
    public function update(User $actor, array $data): User
    {
        try {
            [$user, $fields] = DB::transaction(function () use ($actor, $data) {
                $user = User::whereKey($actor->getKey())->lockForUpdate()->first();
                if (! $user || ! $user->is_active || $user->session_version !== $actor->session_version) {
                    throw new AuthenticationException;
                }
                Gate::forUser($user)->authorize('account.profile');
                $user->fill(['name' => $data['name']]);
                $fields = array_keys($user->getDirty());
                $user->save();
                if ($data['email'] !== $user->email) {
                    app(EmailChange::class)->request($user, $user, $data['email'], $data['locale'] ?? 'en');
                }

                return [$user, $fields];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['users.validation.emailTaken']]);
        }
        if ($fields) {
            Log::notice('account.profile_updated', ['actor_uuid' => $actor->uuid, 'fields' => $fields]);
        }

        return $user;
    }
}
