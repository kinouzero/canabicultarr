<?php

namespace App\Http\Resources;

use App\Services\AssetContexts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeasurementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'value' => $this->value, 'quality' => $this->quality, 'unit' => $this->unit, 'observedAt' => $this->observed_at->toISOString(), 'receivedAt' => $this->received_at->toISOString(), 'location' => $this->location_snapshot, 'provenance' => [...($this->provenance_snapshot ?? ['status' => 'legacy']), 'revisionId' => $this->asset_context_id, 'assignments' => $this->assignmentContext ? AssetContexts::snapshot($this->assignmentContext->snapshot) : null]];
    }
}
