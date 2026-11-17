<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HttpSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'sensor' => new SensorResource($this->sensor), 'isEnabled' => $this->is_enabled, 'version' => $this->version, 'expiresAt' => $this->expires_at->toISOString(), 'credentialCurrent' => $this->credentialCurrent(), 'lastReceivedAt' => $this->last_received_at?->toISOString()];
    }
}
