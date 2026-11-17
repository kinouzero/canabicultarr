<?php

namespace App\Rules;

use App\Exceptions\MeasurementException;
use App\Services\MeasurementValue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AlertBound implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            MeasurementValue::normalize($value);
        } catch (MeasurementException) {
            $fail('alerts.validation.boundInvalid');
        }
    }
}
