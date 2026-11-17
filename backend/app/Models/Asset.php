<?php

namespace App\Models;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use App\Services\AssetContexts;
use App\Services\AssetLocations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class Asset extends Model
{
    use HasUuids;

    public const INTEGRATION_RELATIONS = [
        'homeAssistantDevices as has_home_assistant',
        'homebridgeDevices as has_homebridge',
        'bluetoothDevices as has_bluetooth',
        'mqttSources as has_mqtt',
        'httpSources as has_http',
    ];

    public bool $deferContext = false;

    protected $guarded = ['id', 'name_key', 'asset_tag_key'];

    protected $hidden = ['name_key', 'asset_tag_key'];

    protected $attributes = ['status' => 'active', 'category' => 'other'];

    protected static function booted(): void
    {
        static::saved(function (Asset $asset) {
            if (! $asset->deferContext && ($asset->wasRecentlyCreated || $asset->wasChanged('name'))) {
                app(AssetContexts::class)->capture($asset->id);
            }
        });
        static::saving(function (Asset $asset) {
            $asset->name = trim($asset->name);
            $asset->name_key = Str::lower($asset->name);
            $asset->asset_tag = filled($asset->asset_tag) ? trim($asset->asset_tag) : null;
            $asset->asset_tag_key = $asset->asset_tag !== null ? Str::lower($asset->asset_tag) : null;
        });
    }

    protected function casts(): array
    {
        return ['category' => AssetCategory::class, 'status' => AssetStatus::class, 'pump_control' => 'array', 'power_control' => 'array'];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(AssetLocation::class);
    }

    public static function locationRelations(): array
    {
        return AssetLocations::relations();
    }

    public function locationList(): array
    {
        return $this->locations->map(fn ($location) => $location->locationDetails())->filter()
            ->sortBy(fn ($location) => $location['type'].':'.$location['id'])->values()->all();
    }

    /** A singular context is available only when the assignment is unambiguous. */
    public function locationDetails(): ?array
    {
        $locations = $this->locationList();

        return count($locations) === 1 ? $locations[0] : null;
    }

    public function plants(): BelongsToMany
    {
        return $this->belongsToMany(Plant::class, 'plant_device')->using(PlantDevice::class)
            ->select(['plants.id', 'plants.strain_id', 'plants.name', 'plants.name_key', 'plants.site_id', 'plants.room_id', 'plants.zone_id'])
            ->orderBy('plants.name_key')->orderBy('plants.id');
    }

    public function summaryPlants(): BelongsToMany
    {
        return $this->plants()->limit(3);
    }

    public static function plantRelations(): array
    {
        return ['strain:id,name', ...Plant::locationRelations()];
    }

    public function summaryPlantDetails(): array
    {
        return $this->summaryPlants->map(fn (Plant $plant) => [
            'id' => $plant->id, 'name' => $plant->displayName(), 'location' => $plant->locationDetails(),
        ])->all();
    }

    public static function assignmentRelations(): array
    {
        return [...self::locationRelations(), 'summaryPlants' => fn ($query) => $query->with(self::plantRelations())];
    }

    public function linkedPlantDetails(): array
    {
        return $this->plants->map(fn (Plant $plant) => [
            'id' => $plant->id, 'name' => $plant->displayName(), 'location' => $plant->locationDetails(),
        ])->all();
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(Sensor::class);
    }

    public function homeAssistantDevices(): HasMany
    {
        return $this->hasMany(HomeAssistantDevice::class);
    }

    public function homebridgeDevices(): HasMany
    {
        return $this->hasMany(HomebridgeDevice::class);
    }

    public function bluetoothDevices(): HasMany
    {
        return $this->hasMany(BluetoothDevice::class);
    }

    public function mqttSources(): HasManyThrough
    {
        return $this->hasManyThrough(MqttMeasurementSource::class, Sensor::class);
    }

    public function httpSources(): HasManyThrough
    {
        return $this->hasManyThrough(HttpMeasurementSource::class, Sensor::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }
}
