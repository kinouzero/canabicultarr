<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertEpisode extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rule_snapshot' => 'array', 'first_observation' => 'array', 'last_observation' => 'array', 'resolution_observation' => 'array', 'opened_at' => 'immutable_datetime', 'last_detected_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
