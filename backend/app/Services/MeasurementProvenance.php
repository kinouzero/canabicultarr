<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetContext;
use App\Models\Measurement;
use App\Models\Sensor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class MeasurementProvenance
{
    /** Caller holds the hardware lock; all collectors share the observation-time rule. */
    public function attributes(Asset $asset, Sensor $sensor, string $observedAt, Model $source, string $kind): array
    {
        $time = CarbonImmutable::parse($observedAt)->utc();
        $context = $time->isFuture() ? null : AssetContext::where('asset_id', $asset->id)->where('effective_at', '<=', $time->format('Y-m-d H:i:s.u'))
            ->orderByDesc('effective_at')->orderByDesc('id')->first();

        $locations = $context ? AssetContexts::snapshot($context->snapshot)['locations'] : [];

        return ['asset_context_id' => $context?->id, 'location_snapshot' => count($locations) === 1 ? $locations[0] : null,
            'provenance_snapshot' => ['status' => $context ? 'recorded' : 'unknown',
                'assetId' => $asset->id, 'sensorId' => $sensor->id, 'channelKey' => $sensor->channel_key, 'sensorName' => $sensor->name,
                'source' => ['kind' => $kind, 'id' => $source->id, 'instanceKey' => $source->instance_key],
            ]];
    }

    public function forPlant(?Measurement $reading, string $plantId): bool
    {
        return $reading && collect($reading->assignmentContext?->snapshot['plants'] ?? [])->contains('id', $plantId);
    }
}
