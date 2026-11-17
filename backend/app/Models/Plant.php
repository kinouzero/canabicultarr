<?php

namespace App\Models;

use App\Models\Concerns\HasAssetLocation;
use App\Services\AssetContexts;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Plant extends Model
{
    use HasAssetLocation, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['creation_key', 'creation_hash', 'name_key'];

    protected $attributes = ['version' => 1];

    protected static function booted(): void
    {
        static::saved(function (Plant $plant) {
            if ($plant->wasChanged(['name', 'strain_id', 'site_id', 'room_id', 'zone_id'])) {
                app(AssetContexts::class)->forPlant($plant);
            }
        });
        static::saving(function (Plant $plant) {
            $plant->name = self::normalizeName($plant->name);
            $plant->name_key = Str::lower($plant->name ?? '');
        });
    }

    public static function normalizeName(?string $name): ?string
    {
        $name = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $name ?? '');

        return $name === '' ? null : $name;
    }

    public function displayName(): string
    {
        return self::normalizeName($this->name) ?? $this->strain->name.' · #'.substr($this->id, 0, 8);
    }

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'plant_photos')->withTimestamps();
    }

    protected function casts(): array
    {
        return ['summary_sensor_ids' => 'array', 'harvest_weight_grams' => 'decimal:2', 'started_at' => 'immutable_datetime', 'phase_started_at' => 'immutable_datetime', 'phase_due_at' => 'immutable_datetime', 'container_snapshot' => 'array', 'duration_snapshot' => 'array', 'version' => 'integer'];
    }

    public function photoDocument()
    {
        return $this->belongsTo(Document::class, 'photo_document_id');
    }

    public function strain()
    {
        return $this->belongsTo(Strain::class);
    }

    public function devices()
    {
        return $this->belongsToMany(Asset::class, 'plant_device')->using(PlantDevice::class);
    }

    public function recentWaterings()
    {
        return $this->hasMany(PlantEvent::class)->where('kind', 'watered')->orderByDesc('occurred_at')->orderByDesc('plant_version')->limit(1);
    }

    public function events()
    {
        return $this->hasMany(PlantEvent::class);
    }
}
