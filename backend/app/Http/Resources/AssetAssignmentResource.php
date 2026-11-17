<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'assignedAt' => $this->assigned_at->toIso8601String(),
            'locations' => $this->locations_snapshot ?? array_values(array_filter([$this->resource->locationDetails()])), 'changedBy' => $this->user?->uuid,
        ];
    }
}
