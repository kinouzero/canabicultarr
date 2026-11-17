<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeasurementTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'unit' => $this->unit,
            'nature' => $this->nature, 'decimalPlaces' => $this->decimal_places, 'description' => $this->description, 'sensorsCount' => (int) $this->sensors_count];
    }
}
