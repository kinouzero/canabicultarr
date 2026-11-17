<?php

namespace App\Http\Resources;

use App\Models\HomebridgeLink;
use App\Services\RemoteSensorCollection;
use App\Services\RemoteSyncSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RemoteSensorLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $client = app(RemoteSensorCollection::class)->client($this->resource instanceof HomebridgeLink ? 'hb' : 'ha');

        return ['nextSyncAt' => app(RemoteSyncSchedule::class)->nextEligibleAt($client, $this->resource), 'id' => $this->id, 'entityId' => $this->entity_id, 'sensor' => new SensorResource($this->sensor), 'isEnabled' => $this->is_enabled, 'version' => $this->version, 'lastAttemptAt' => $this->last_attempt_at?->toISOString(), 'lastSuccessAt' => $this->last_success_at?->toISOString(), 'lastError' => $this->last_error];
    }
}
