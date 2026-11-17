<?php

namespace App\Http\Resources;

use App\Services\PlantMeasurements;
use App\Services\PlantWatering;
use Illuminate\Http\Request;

class PlantCardResource extends LocationAwareResource
{
    public function toArray(Request $request): array
    {
        return [
            'canDelete' => $request->user()->can('delete', $this->resource),
            'canManage' => $request->user()->can('update', $this->resource),
            'id' => $this->id, 'name' => $this->resource->displayName(), 'givenName' => $this->name,
            'strain' => ['id' => $this->strain->id, 'name' => $this->strain->name, 'classification' => $this->strain->classification, 'genetics' => $this->strain->genetics],
            ...PlantResource::phaseData($this->resource),
            'location' => $this->resource->locationDetails(),
            'watering' => PlantWatering::state($this->resource),
            'measurements' => app(PlantMeasurements::class)->state($this->resource),
            'summarySensorIds' => app(PlantMeasurements::class)->summaryIds($this->resource),
            'photoUrl' => PlantResource::photoUrl($this->resource, $request->user()),
        ];
    }
}
