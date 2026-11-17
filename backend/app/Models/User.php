<?php

namespace App\Models;

use App\Enums\DelegatedPermission;
use Database\Factories\UserFactory;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    private ?array $pendingPermissionRoleIds = null;

    protected $attributes = ['is_active' => true, 'session_version' => 0];

    public function pendingEmailChange()
    {
        return $this->hasOne(PendingEmailChange::class);
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->uuid ??= (string) Str::uuid();
            $user->pendingPermissionRoleIds ??= [AccessProfileSeeder::defaultProfile()->id];
        });
        static::saved(function (User $user): void {
            if ($user->pendingPermissionRoleIds !== null) {
                $user->permissionRoles()->sync($user->pendingPermissionRoleIds);
                $user->pendingPermissionRoleIds = null;
                $user->unsetRelation('permissionRoles');
            }
        });
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'session_version',
        'email_activation_required',
        'permission_grants',
        'permission_denials',
        'permission_role_ids',
        'location_access',
        'inventory_location_access',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'email_activation_required' => 'boolean',
            'session_version' => 'integer',
            'permission_grants' => 'array',
            'permission_denials' => 'array',
            'location_access' => 'array',
            'inventory_location_access' => 'array',
        ];
    }

    public function avatarUrl(): string
    {
        $identifier = hash('sha256', mb_strtolower(trim($this->email)));

        return 'https://gravatar.com/avatar/'.$identifier.'?s=128&d=404&r=g';
    }

    public function delegatedPermissions(): array
    {
        $values = is_array($this->permission_grants) ? $this->permission_grants : [];
        $values = array_values(array_unique(array_filter($values, fn ($value) => is_string($value) && in_array($value, DelegatedPermission::values(), true))));
        sort($values);

        return $values;
    }

    public function permissionRoles()
    {
        return $this->belongsToMany(PermissionRole::class, 'permission_role_user')->orderBy('permission_roles.id');
    }

    public function getPermissionRoleIdsAttribute(): array
    {
        return $this->pendingPermissionRoleIds ?? $this->permissionRoles->modelKeys();
    }

    public function setPermissionRoleIdsAttribute(array $ids): void
    {
        $this->pendingPermissionRoleIds = array_values(array_unique($ids));
        sort($this->pendingPermissionRoleIds);
    }

    public function deniedPermissions(): array
    {
        return array_values(array_intersect(is_array($this->permission_denials) ? $this->permission_denials : [], DelegatedPermission::values()));
    }

    public function effectiveOperationalPermissions(): array
    {
        $profileGrants = $this->permissionRoles->where('is_archived', false)->flatMap(fn ($profile) => $profile->grants())->all();
        $grants = array_values(array_diff(array_unique([...$this->delegatedPermissions(), ...$profileGrants]), $this->deniedPermissions()));
        sort($grants);

        return $grants;
    }

    public function hasOperationalPermission(DelegatedPermission $permission): bool
    {
        return $this->is_active && in_array($permission->value, $this->effectiveOperationalPermissions(), true);
    }

    public function isAdministrator(): bool
    {
        return $this->hasOperationalPermission(DelegatedPermission::Administration);
    }

    public function scopeWithoutPendingActivation($query)
    {
        return $query->where(fn ($query) => $query->where('email_activation_required', false)->orWhereNotNull('email_verified_at'));
    }

    public function scopeWithPermission($query, string $permission)
    {
        return $query->where(fn ($q) => $q->whereNull('permission_denials')->orWhereJsonDoesntContain('permission_denials', $permission))
            ->where(fn ($q) => $q->whereJsonContains('permission_grants', $permission)
                ->orWhereHas('permissionRoles', fn ($p) => $p->where('is_archived', false)->whereJsonContains('permissions', $permission)));
    }
}
