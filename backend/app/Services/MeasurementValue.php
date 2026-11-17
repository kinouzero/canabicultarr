<?php

namespace App\Services;

use App\Exceptions\MeasurementException;

class MeasurementValue
{
    public static function normalize(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 80 || ! preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]{1,2}))?$/D', $value, $m)) {
            throw new MeasurementException('measurements.errors.invalidValue');
        }
        $digits = $m[2].($m[3] ?? '');
        $point = strlen($m[2]) + (int) ($m[4] ?? 0);
        if ($point < 0) {
            $digits = str_repeat('0', -$point).$digits;
            $point = 0;
        }
        $digits = str_pad($digits, $point, '0');
        $whole = ltrim(substr($digits, 0, $point), '0') ?: '0';
        $fraction = rtrim(substr($digits, $point), '0');
        if (strlen($whole) > 12 || strlen($fraction) > 9) {
            throw new MeasurementException('measurements.errors.invalidValue');
        }
        $negative = $m[1] === '-' && ($whole !== '0' || $fraction !== '');

        return ($negative ? '-' : '').$whole.'.'.str_pad($fraction, 9, '0');
    }
}
