<?php

namespace App\Services;

use App\Exceptions\AuthFlowException;
use App\Exceptions\UserAdministrationException;
use App\Models\PermissionRole;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserAdministration
{
    public const SORT_COLUMNS = ['name' => 'name', 'created' => 'created_at', 'email' => 'email'];

    public function search(array $criteria)
    {
        $query = User::with('permissionRoles', 'pendingEmailChange.requester');
        $search = trim($criteria['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%';
            $query->where(function ($query) use ($pattern) {
                $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        if (filter_var($criteria['administrators'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->withPermission('administration.view');
        }
        if (filled($criteria['status'] ?? null)) {
            $query->where('is_active', $criteria['status'] === 'active');
        }
        if (filled($criteria['permission_role_id'] ?? null)) {
            $query->whereHas('permissionRoles', fn ($profiles) => $profiles->whereKey($criteria['permission_role_id']));
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, self::SORT_COLUMNS)->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function summary(): array
    {
        $counts = User::selectRaw('COUNT(*) AS total, SUM(CASE WHEN is_active THEN 1 ELSE 0 END) AS active')->first();

        return ['total' => (int) $counts->total, 'active' => (int) $counts->active, 'inactive' => (int) $counts->total - (int) $counts->active, 'administrators' => User::withPermission('administration.view')->count()];
    }

    public function create(User $actor, array $data): User
    {
        try {
            $user = DB::transaction(function () use ($actor, $data) {
                $this->lockAdministrators();
                Gate::forUser($actor->fresh())->authorize('create', User::class);
                $this->assertLocalActivationReady();
                $user = new User([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                ]);
                $user->is_active = $data['is_active'] ?? true;
                $user->email_activation_required = true;
                $this->applyAccess($user, $data);
                $user->save();
                if ($user->is_active) {
                    app(AccountActivation::class)->begin($user, $data['locale'] ?? 'en');
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw new UserAdministrationException('users.errors.emailTaken', 422);
        }

        Log::notice('user.created', ['actor_uuid' => $actor->uuid, 'user_uuid' => $user->uuid, 'profile_uuids' => $user->permission_role_ids]);

        return $user;
    }

    public function update(User $actor, User $target, array $data): User
    {
        try {
            [$user, $fields] = DB::transaction(function () use ($actor, $target, $data) {
                $administrators = $this->lockAdministrators();
                $expectedVersion = $actor->session_version;
                $actor = $actor->fresh();
                if (! $actor || ! $actor->is_active || $actor->session_version !== $expectedVersion) {
                    throw new AuthenticationException;
                }
                $user = User::whereKey($target->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $user);

                if ($user->is($actor) && ! $data['is_active']) {
                    throw new UserAdministrationException('users.errors.cannotDisableSelf');
                }
                $wasAdministrator = $administrators->contains('id', $user->id);
                $previousProfiles = $user->permission_role_ids;
                $emailChanging = $data['email'] !== $user->email;
                $user->forceFill(['name' => $data['name'], 'is_active' => $data['is_active']]);
                $this->applyAccess($user, $data);
                $profilesChanged = $previousProfiles !== $user->permission_role_ids;
                if ($wasAdministrator && ! $user->isAdministrator() && $administrators->count() < 2) {
                    throw new UserAdministrationException('users.errors.lastAdministrator');
                }
                if (! empty($data['password'])) {
                    $user->password = Hash::make($data['password']);
                }
                $needsActivation = $user->is_active && $user->email_activation_required && ! $user->email_verified_at
                    && $user->isDirty('is_active') && ! $emailChanging;
                $fields = array_values(array_intersect(array_keys($user->getDirty()), ['name', 'email', 'is_active', 'password', 'permission_grants', 'permission_denials', 'permission_role_ids', 'location_access', 'inventory_location_access']));
                if ($profilesChanged) {
                    $fields[] = 'permission_role_ids';
                }
                if ($profilesChanged || $user->isDirty(['email', 'is_active', 'password', 'permission_grants', 'permission_denials', 'permission_role_ids', 'location_access', 'inventory_location_access'])) {
                    app(AccountSecurity::class)->clearResetTokens([$user->getOriginal('email'), $user->email]);
                    app(AccountSecurity::class)->invalidate($user);
                }
                $user->save();
                if ($emailChanging) {
                    app(EmailChange::class)->request($actor->is($user) ? $user : $actor, $user, $data['email'], $data['locale'] ?? 'en');
                }
                if ($needsActivation) {
                    $this->assertLocalActivationReady();
                    app(AccountActivation::class)->begin($user, $data['locale'] ?? 'en');
                }

                return [$user, $fields];
            });
        } catch (UniqueConstraintViolationException) {
            throw new UserAdministrationException('users.errors.emailTaken', 422);
        }

        Log::notice('user.updated', ['actor_uuid' => $actor->uuid, 'user_uuid' => $user->uuid, 'fields' => $fields]);

        return $user;
    }

    private function assertLocalActivationReady(): void
    {
        if (! config('auth.local_enabled')) {
            throw new UserAdministrationException('users.errors.localCreationDisabled');
        }
        if (! app(AccountActivation::class)->ready()) {
            throw new AuthFlowException('accountActivation.unavailable', 503);
        }
    }

    private function applyAccess(User $user, array $data): void
    {
        $user->permission_role_ids = app(PermissionRoleAdministration::class)->assignments($data['permission_role_ids']);
        $profiles = PermissionRole::whereKey($user->permission_role_ids)->orderBy('id')->get();
        $user->setRelation('permissionRoles', $profiles);
        foreach (['permission_grants', 'permission_denials'] as $field) {
            if (array_key_exists($field, $data)) {
                $values = $data[$field];
                sort($values);
                $current = $field === 'permission_grants' ? $user->delegatedPermissions() : $user->deniedPermissions();
                if ($values !== $current) {
                    $user->{$field} = $values;
                }
            }
        }
        if (array_intersect($user->delegatedPermissions(), $user->deniedPermissions())) {
            throw ValidationException::withMessages(['permission_denials' => ['users.validation.permissionsInvalid']]);
        }
        if (array_key_exists('inventory_location_access', $data) || $user->isAdministrator()) {
            $user->inventory_location_access = $user->isAdministrator() ? null : app(InventoryLocations::class)->normalize($data['inventory_location_access'] ?? null);
        }
        if (array_key_exists('location_access', $data) || $user->isAdministrator()) {
            $user->location_access = $user->isAdministrator() ? null : app(LocationAccess::class)->normalize($data['location_access'] ?? null);
        }
    }

    private function lockAdministrators()
    {
        return PermissionRoleAdministration::lockAdministrators();
    }
}
