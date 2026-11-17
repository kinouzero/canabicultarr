<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $attributes = ['is_enabled' => true, 'version' => 1, 'last_result' => 'waiting'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'version' => 'integer', 'delay_seconds' => 'integer', 'max_age_seconds' => 'integer', 'activated_at' => 'immutable_datetime', 'last_evaluated_at' => 'immutable_datetime'];
    }

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(AlertEpisode::class);
    }
}
