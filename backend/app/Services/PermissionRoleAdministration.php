<?php

namespace App\Services;

use App\Exceptions\UserAdministrationException;
use App\Models\ApplicationSetup;
use App\Models\PermissionRole;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PermissionRoleAdministration
{
    public const SORT_COLUMNS = ['name' => 'name_key', 'created' => 'created_at', 'users' => 'users_count'];

    public static function lockAdministrators()
    {
        // Membership EXISTS predicates can retain an old PostgreSQL statement
        // snapshot while waiting on user rows. Serialize first, then query rights.
        ApplicationSetup::whereKey(1)->lockForUpdate()->firstOrFail();

        return User::withPermission('administration.view')->withoutPendingActivation()->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
    }

    public function search(array $criteria)
    {
        $query = PermissionRole::withCount('users');
        $status = $criteria['status'] ?? 'active';
        if ($status !== 'all') {
            $query->where('is_archived', $status === 'archived');
        }
        if (filled($criteria['search'] ?? null)) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower(trim($criteria['search']))).'%';
            $query->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern]);
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, self::SORT_COLUMNS)->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function assignments(array $ids): array
    {
        sort($ids);
        $roles = PermissionRole::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        if ($ids === [] || $roles->count() !== count($ids) || $roles->contains('is_archived', true)) {
            throw new UserAdministrationException('accessProfiles.errors.unavailable', 422);
        }

        return $ids;
    }

    public function save(User $actor, array $data, ?PermissionRole $target = null): PermissionRole
    {
        try {
            [$role, $affected, $fields] = DB::transaction(function () use ($actor, $data, $target) {
                self::lockAdministrators();
                $current = $actor->fresh();
                $role = $target ? PermissionRole::whereKey($target->id)->lockForUpdate()->firstOrFail() : new PermissionRole;
                Gate::forUser($current)->authorize($target ? 'update' : 'create', $target ? $role : PermissionRole::class);
                if ($target && $role->version !== (int) $data['version']) {
                    throw new UserAdministrationException('accessProfiles.errors.conflict', 409);
                }
                $permissions = $data['permissions'];
                sort($permissions);
                $permissionsChanged = $target && $role->grants() !== $permissions;
                $archived = (bool) ($data['is_archived'] ?? $role->is_archived);
                if ($archived && $role->exists && $role->users()->exists()) {
                    throw new UserAdministrationException('accessProfiles.errors.assigned', 409);
                }
                $role->fill(['name' => trim($data['name']), 'description' => array_key_exists('description', $data) ? $data['description'] : $role->description, 'permissions' => $permissions, 'is_archived' => $archived]);
                if ($permissionsChanged && $role->isDirty('permissions')) {
                    $administrators = User::withPermission('administration.view')->withoutPendingActivation()->where('is_active', true)->with('permissionRoles')->get();
                    if (! $administrators->contains(function ($user) use ($role) {
                        $user->setRelation('permissionRoles', $user->permissionRoles->map(fn ($assigned) => $assigned->is($role) ? $role : $assigned));

                        return $user->isAdministrator();
                    })) {
                        throw new UserAdministrationException('users.errors.lastAdministrator');
                    }
                }
                $fields = array_values(array_intersect(array_keys($role->getDirty()), ['name', 'description', 'permissions', 'is_archived']));
                if ($target && $fields !== []) {
                    $role->version++;
                }
                $affected = 0;
                if ($permissionsChanged) {
                    // Same global administrator lock serializes profile edits with assignments.
                    User::whereHas('permissionRoles', fn ($profiles) => $profiles->whereKey($role->id))->orderBy('id')->chunkById(100, function ($users) use (&$affected) {
                        foreach ($users as $user) {
                            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                            app(AccountSecurity::class)->invalidate($locked);
                            $affected++;
                        }
                    });
                }
                $role->save();

                return [$role->loadCount('users'), $affected, $fields];
            });
        } catch (UniqueConstraintViolationException) {
            throw new UserAdministrationException('accessProfiles.errors.nameTaken', 422);
        }
        Log::notice($target ? 'access_profile.updated' : 'access_profile.created', ['actor_uuid' => $actor->uuid, 'profile_uuid' => $role->id, 'fields' => $fields, 'affected_accounts' => $affected]);

        return $role;
    }
}
