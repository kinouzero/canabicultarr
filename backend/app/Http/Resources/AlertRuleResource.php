<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AlertRuleResource extends LocationAwareResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'kind' => $this->kind, 'severity' => $this->severity, 'sensor' => new SensorResource($this->sensor), 'minimum' => $this->minimum, 'maximum' => $this->maximum, 'delaySeconds' => $this->delay_seconds, 'maxAgeSeconds' => $this->max_age_seconds, 'isEnabled' => $this->is_enabled, 'version' => $this->version, 'activatedAt' => $this->activated_at->toISOString(), 'lastEvaluatedAt' => $this->last_evaluated_at?->toISOString(), 'lastResult' => $this->last_result, 'canManage' => $request->user()->can('update', $this->resource)];
    }
}
