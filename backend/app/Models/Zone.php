<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Zone extends Location
{
    protected $fillable = ['name', 'code', 'description', 'watering_alert_days'];

    public function kind(): string
    {
        return 'zone';
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
