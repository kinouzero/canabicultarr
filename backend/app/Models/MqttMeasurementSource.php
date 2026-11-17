<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MqttMeasurementSource extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['instance_key'];

    protected $attributes = ['is_enabled' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'version' => 'integer', 'last_received_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime'];
    }

    public function sensor()
    {
        return $this->belongsTo(Sensor::class);
    }
}
