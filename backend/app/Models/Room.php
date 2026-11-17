<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Location
{
    protected $fillable = ['name', 'code', 'description', 'watering_alert_days'];

    public function kind(): string
    {
        return 'room';
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function childRelation(): ?string
    {
        return 'zones';
    }
}
