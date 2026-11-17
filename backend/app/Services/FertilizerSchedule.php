<?php

namespace App\Services;

use App\Models\Plant;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class FertilizerSchedule
{
    private const DAY_MICROSECONDS = 86400000000;

    public function validate(array $stages, array $durations): void
    {
        foreach ($stages as $index => $stage) {
            $periods = $stage['fertilizer_periods'] ?? [];
            $path = "stages.$index.fertilizer_periods";
            $fail = fn ($field = '') => throw ValidationException::withMessages([$path.$field => 'plants.fertilizerSchedule.invalid']);
            if ($periods && ! $stage['watering']) {
                $fail();
            }
            $ids = [];
            $products = [];
            foreach ($periods as $row => $period) {
                $maximum = match ($period['unit']) {
                    'days' => 3650, 'weeks' => 521, 'percent' => 100
                };
                if (in_array(strtolower($period['id']), $ids, true)) {
                    $fail(".$row.id");
                }
                $ids[] = strtolower($period['id']);
                if ($period['start'] < 0 || $period['start'] >= $maximum || ($period['end'] !== null && ($period['end'] <= $period['start'] || $period['end'] > $maximum))) {
                    $fail(".$row.end");
                }
                if ($period['unit'] === 'percent' && ! isset($durations[$stage['id']])) {
                    $fail(".$row.unit");
                }
                $products = [...$products, ...array_column($period['products'], 'inventory_item_id')];
            }
            if (count(array_unique($products)) > 20) {
                $fail();
            }
        }
    }

    public function normalize(array $stages): array
    {
        foreach ($stages as &$stage) {
            foreach ($stage['fertilizer_periods'] ?? [] as $index => $period) {
                $period['start'] = (int) $period['start'];
                $period['end'] = $period['end'] === null ? null : (int) $period['end'];
                if ($period['unit'] === 'weeks') {
                    $period['unit'] = 'days';
                    $period['start'] *= 7;
                    $period['end'] = $period['end'] === null ? null : $period['end'] * 7;
                }
                $stage['fertilizer_periods'][$index] = $period;
            }
        }
        unset($stage);

        return $stages;
    }

    /** Resolve half-open intervals in UTC; display rounding never determines membership. */
    public function forPlant(Plant $plant, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now('UTC');
        $start = $plant->phase_started_at;
        $due = $plant->phase_due_at;
        $days = $plant->duration_snapshot['durations'][$plant->phase] ?? null;
        $duration = $start && $due ? max(0, (int) $start->diffInMicroseconds($due, false)) : ($days === null ? null : $days * self::DAY_MICROSECONDS);
        $flush = PlantWatering::flushStart($plant);
        $limit = $duration;
        if ($start && $flush) {
            $flushOffset = max(0, (int) $start->diffInMicroseconds($flush, false));
            $limit = $limit === null ? $flushOffset : min($limit, $flushOffset);
        }
        $elapsed = $start ? (int) $start->diffInMicroseconds($at, false) : null;
        $periods = [];
        $selected = [];
        foreach (LifecycleDefinition::stage($plant->phase)['fertilizer_periods'] ?? [] as $period) {
            $from = $this->offset($period['start'], $period['unit'], $duration);
            $until = $period['end'] === null ? $duration : $this->offset($period['end'], $period['unit'], $duration);
            if ($limit !== null) {
                $until = $until === null ? $limit : min($until, $limit);
            }
            $unknown = $elapsed === null || $from === null || ($period['unit'] === 'percent' && $duration === null);
            $outside = ! $unknown && $until !== null && $from >= $until;
            $active = ! $unknown && ! $outside && $elapsed >= $from && ($until === null || $elapsed < $until);
            $status = $unknown ? 'unknown' : ($outside ? 'outside' : ($active ? 'active' : ($elapsed < $from ? 'upcoming' : 'completed')));
            $periods[] = [...$period, 'status' => $status, 'active' => $active,
                'startDays' => $from === null ? null : $from / self::DAY_MICROSECONDS,
                'endDays' => $until === null ? null : $until / self::DAY_MICROSECONDS,
                'startsAt' => $start && $from !== null ? $start->addMicroseconds($from)->toISOString() : null,
                'endsAt' => $start && $until !== null ? $start->addMicroseconds($until)->toISOString() : null];
            if ($active) {
                foreach ($period['products'] as $product) {
                    $id = $product['inventory_item_id'];
                    // One proposal per product; the latest-starting period overrides its dose.
                    if (! isset($selected[$id]) || $from >= $selected[$id]['start']) {
                        $selected[$id] = ['start' => $from, 'product' => $product];
                    }
                }
            }
        }

        return ['at' => $at->toISOString(), 'stageStartedAt' => $start?->toISOString(),
            'durationDays' => $duration === null ? null : $duration / self::DAY_MICROSECONDS,
            'flushStartsAt' => $flush?->toISOString(), 'periods' => $periods,
            'products' => array_values(array_map(fn ($entry) => $entry['product'], $selected))];
    }

    private function offset(int $value, string $unit, ?int $duration): ?int
    {
        return match ($unit) {
            'days' => $value * self::DAY_MICROSECONDS,
            'weeks' => $value * 7 * self::DAY_MICROSECONDS,
            'percent' => $duration === null ? null : intdiv($duration * $value, 100),
        };
    }
}
