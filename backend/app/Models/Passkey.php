<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Passkey extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'rp_id', 'credential_hash', 'name', 'credential', 'last_used_at'];

    protected $hidden = ['user_id', 'credential_hash', 'credential'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'credential' => 'array', 'last_used_at' => 'immutable_datetime'];
    }
}
