<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PumpActivation extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['control' => 'array', 'created_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'stop_due_at' => 'immutable_datetime', 'stopped_at' => 'immutable_datetime'];
    }
}
