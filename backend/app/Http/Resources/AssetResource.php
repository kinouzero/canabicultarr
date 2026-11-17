<?php

namespace App\Http\Resources;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use App\Enums\DelegatedPermission;
use App\Services\LocationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AssetResource extends LocationAwareResource
{
    public function toArray(Request $request): array
    {
        $controlProviders = [$this->power_control['provider'] ?? null, $this->pump_control['provider'] ?? null];
        $integrations = array_filter(
            ['home_assistant', 'homebridge', 'bluetooth', 'mqtt', 'http'],
            fn (string $provider) => $this->resource->getAttribute('has_'.$provider) || in_array($provider, $controlProviders, true),
        );

        return [
            'integrations' => array_values($integrations),
            'importedControlProvider' => match (true) {
                (bool) $this->resource->getAttribute('has_home_assistant') => 'home_assistant',
                (bool) $this->resource->getAttribute('has_homebridge') => 'homebridge',
                default => null,
            },
            'pumpControl' => $this->pump_control ? Arr::only($this->pump_control, ['provider', 'entity', 'mode', 'seconds']) : null, 'canActivate' => $this->category === AssetCategory::Pump && $this->status === AssetStatus::Active && $this->pump_control !== null && $request->user()->can('update', $this->resource),
            'powerControl' => $this->power_control ? Arr::only($this->power_control, ['provider', 'entity', 'mode', 'on_at', 'off_at', 'timezone']) : null,
            'canConfigurePower' => $request->user()->hasOperationalPermission(DelegatedPermission::HomeAssistant) && ! app(LocationAccess::class)->restricted($request->user()),
            'canControlPower' => $this->category->supportsPowerControl() && $this->status === AssetStatus::Active && $this->power_control !== null && $request->user()->can('update', $this->resource),
            'id' => $this->id, 'category' => $this->category->value, 'name' => $this->name,
            'status' => $this->status->value, 'assetTag' => $this->asset_tag,
            'manufacturer' => $this->manufacturer, 'model' => $this->model,
            'serialNumber' => $this->serial_number, 'description' => $this->description,
            'locations' => $this->resource->locationList(),
            'plants' => $this->resource->summaryPlantDetails(), 'plantsCount' => (int) $this->plants_count,
            'channelsCount' => (int) $this->sensors_count, 'enabledChannelsCount' => (int) $this->enabled_channels_count,
        ];
    }
}
