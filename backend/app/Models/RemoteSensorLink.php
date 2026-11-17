<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

abstract class RemoteSensorLink extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = ['is_enabled' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'version' => 'integer', 'last_attempt_at' => 'immutable_datetime', 'last_success_at' => 'immutable_datetime'];
    }

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }
}
