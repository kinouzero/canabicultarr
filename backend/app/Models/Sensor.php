<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Sensor extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'channel_key', 'is_enabled', 'expected_interval_seconds', 'description'];

    protected $hidden = ['name_key'];

    protected $attributes = ['is_enabled' => true];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'expected_interval_seconds' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (Sensor $sensor) {
            $sensor->name = trim($sensor->name);
            $sensor->name_key = Str::lower($sensor->name);
            $sensor->channel_key = Str::lower(trim($sensor->channel_key));
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function measurementType(): BelongsTo
    {
        return $this->belongsTo(MeasurementType::class);
    }
}
