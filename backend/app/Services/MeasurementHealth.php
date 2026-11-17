<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\Measurement;
use App\Models\Sensor;
use Carbon\CarbonImmutable;

class MeasurementHealth
{
    public static function status(Sensor $sensor, ?Measurement $latest, CarbonImmutable $now): string
    {
        if (! $sensor->is_enabled || $sensor->device->status !== AssetStatus::Active) {
            return 'disabled';
        }
        if ($latest === null) {
            return 'missing';
        }
        if ($sensor->expected_interval_seconds === null) {
            return 'unknown';
        }

        return $latest->observed_at->lessThan($now->subSeconds(max(120, 2 * $sensor->expected_interval_seconds))) ? 'stale' : 'fresh';
    }
}
