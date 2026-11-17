<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceCommand extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_on' => 'boolean', 'control' => 'array', 'created_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime'];
    }
}
