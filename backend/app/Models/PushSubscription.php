<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    use HasUuids;

    protected $hidden = ['endpoint', 'keys', 'endpoint_hash', 'session_hash'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'keys' => 'encrypted:array', 'version' => 'integer', 'user_version' => 'integer', 'last_used_at' => 'immutable_datetime'];
    }
}
