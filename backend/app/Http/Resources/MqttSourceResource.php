<?php

namespace App\Http\Resources;

use App\Services\MqttConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MqttSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'sensor' => new SensorResource($this->sensor), 'topic' => $this->topic, 'isEnabled' => $this->is_enabled, 'version' => $this->version, 'instanceCurrent' => $this->instance_key === app(MqttConfiguration::class)->instanceKey(), 'lastReceivedAt' => $this->last_received_at?->toISOString(), 'lastAttemptAt' => $this->last_attempt_at?->toISOString(), 'lastError' => in_array($this->last_error, ['mqtt.errors.invalidSample', 'mqtt.errors.sampleConflict', 'mqtt.errors.failed'], true) ? $this->last_error : null];
    }
}
