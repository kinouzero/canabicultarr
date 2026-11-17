<?php

namespace App\Services;

use App\Models\Plant;
use Carbon\CarbonImmutable;

class PlantWatering
{
    public static function flushStart(Plant $plant): ?CarbonImmutable
    {
        $days = $plant->duration_snapshot['durations']['flush'] ?? 0;
        if (! (LifecycleDefinition::stage($plant->phase)['flush'] ?? false) || ! $plant->phase_started_at || ! $plant->phase_due_at || $days <= 0) {
            return null;
        }

        return $plant->phase_due_at->subDays($days)->max($plant->phase_started_at);
    }

    public static function duringFlush(Plant $plant, CarbonImmutable $time): bool
    {
        $start = self::flushStart($plant);

        return $start !== null && $time->greaterThanOrEqualTo($start);
    }

    public static function state(Plant $plant): array
    {
        $latest = $plant->recentWaterings->first();
        $available = in_array($plant->phase, LifecycleDefinition::allowing('watering'), true);
        $flush = self::duringFlush($plant, CarbonImmutable::now('UTC'));
        $last = $latest ? ['occurredAt' => $latest->occurred_at->toISOString(), 'withProducts' => $latest->snapshot['watering']['with_products']] : null;

        return [
            'status' => ! $available ? 'unavailable' : ($flush ? 'flush' : ($last ? 'alternate' : 'choose')),
            'last' => $last,
            'nextWithProducts' => ! $available ? null : ($flush ? false : ($last ? ! $last['withProducts'] : null)),
            'flushStartsAt' => self::flushStart($plant)?->toISOString(),
            'need' => app(PlantWateringNeeds::class)->state($plant),
            'flushDays' => $plant->duration_snapshot['durations']['flush'] ?? 0,
        ];
    }
}
