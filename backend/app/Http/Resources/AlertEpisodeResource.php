<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertEpisodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'rule' => $this->rule_snapshot, 'severity' => $this->severity, 'reason' => $this->reason, 'firstObservation' => $this->first_observation, 'lastObservation' => $this->last_observation, 'resolutionObservation' => $this->resolution_observation, 'openedAt' => $this->opened_at->toISOString(), 'lastDetectedAt' => $this->last_detected_at->toISOString(), 'resolvedAt' => $this->resolved_at?->toISOString(), 'resolution' => $this->resolution, 'acknowledgedAt' => $this->acknowledged_at?->toISOString(), 'acknowledger' => $this->acknowledger_uuid ? ['id' => $this->acknowledger_uuid, 'name' => $this->acknowledger?->name] : null];
    }
}
