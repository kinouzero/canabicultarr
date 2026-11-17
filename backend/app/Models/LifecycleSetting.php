<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LifecycleSetting extends Model
{
    public $incrementing = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['automatic_relocation' => 'boolean', 'stages' => 'array', 'durations' => 'array', 'version' => 'integer', 'watering_alert_days' => 'integer', 'watering_moisture_threshold' => 'float'];
    }
}
