<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeasurementRetentionPolicy extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['days' => 'integer', 'version' => 'integer', 'approved_session_version' => 'integer', 'approved_at' => 'immutable_datetime'];
    }
}
