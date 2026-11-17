<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MeasurementReportExport extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'queued', 'progress' => 0, 'processed_rows' => 0];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['access_fingerprint', 'actor_session_version', 'request_key'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'display' => 'array', 'actor_session_version' => 'integer', 'progress' => 'integer', 'processed_rows' => 'integer', 'total_rows' => 'integer', 'bytes' => 'integer', 'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
