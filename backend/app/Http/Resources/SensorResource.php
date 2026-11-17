<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SensorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'switchControl' => $this->whenLoaded('switchControl'),
            'id' => $this->id, 'name' => $this->name, 'channelKey' => $this->channel_key,
            'isEnabled' => $this->is_enabled, 'expectedIntervalSeconds' => $this->expected_interval_seconds,
            'description' => $this->description,
            'device' => ['id' => $this->device->id, 'name' => $this->device->name, 'category' => $this->device->category->value, 'status' => $this->device->status->value, 'locations' => $this->device->locationList(), 'plants' => $this->device->summaryPlantDetails(), 'plantsCount' => (int) $this->device->plants_count],
            'measurementType' => ['id' => $this->measurementType->id, 'code' => $this->measurementType->code,
                'name' => $this->measurementType->name, 'nature' => $this->measurementType->nature, 'unit' => $this->measurementType->unit, 'decimalPlaces' => $this->measurementType->decimal_places],
        ];
    }
}
