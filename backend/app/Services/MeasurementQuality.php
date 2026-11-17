<?php

namespace App\Services;

use App\Enums\MeasurementQuality as Quality;
use App\Exceptions\MeasurementException;
use App\Models\MeasurementType;

class MeasurementQuality
{
    public static function normalize(mixed $quality, string $value, MeasurementType $type): string
    {
        $quality ??= 'unknown';
        if (! is_string($quality) || Quality::tryFrom($quality) === null) {
            throw new MeasurementException('measurements.errors.invalidQuality');
        }
        if (in_array($quality, Quality::USABLE, true)
            && in_array($type->nature, ['air_humidity', 'substrate_moisture', 'water_level', 'battery'], true)
            && $type->unit === '%' && (bccomp($value, '0', 9) < 0 || bccomp($value, '100', 9) > 0)) {
            return 'suspect';
        }

        return $quality;
    }
}
