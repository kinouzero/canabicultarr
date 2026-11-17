<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MeasurementPurgeRun extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'queued', 'deleted_count' => 0];

    protected $hidden = ['confirmation_key', 'actor_session_version'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['days' => 'integer', 'policy_version' => 'integer', 'actor_session_version' => 'integer', 'deleted_count' => 'integer', 'cutoff_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
