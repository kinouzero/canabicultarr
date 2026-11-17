<?php

namespace App\Models;

use App\Enums\DelegatedPermission;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PermissionRole extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'description', 'permissions', 'is_archived'];

    protected $attributes = ['is_archived' => false, 'version' => 1];

    protected static function booted(): void
    {
        static::saving(function (PermissionRole $role) {
            $role->name = trim($role->name);
            $role->name_key = Str::lower($role->name);
        });
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_archived' => 'boolean', 'version' => 'integer'];
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'permission_role_user');
    }

    public function grants(): array
    {
        $grants = array_values(array_unique(array_filter(is_array($this->permissions) ? $this->permissions : [], fn ($value) => is_string($value) && in_array($value, DelegatedPermission::values(), true))));
        sort($grants);

        return $grants;
    }
}
