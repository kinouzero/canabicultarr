<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ZonedTimestamp implements ValidationRule
{
    public function __construct(private string $errorCode = 'events.validation.timeInvalid') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valid = is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|([+-])(\d{2}):(\d{2}))$/D', $value, $parts);
        if (! $valid || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || (isset($parts[8]) && ((int) $parts[8] > 23 || (int) $parts[9] > 59))) {
            $fail($this->errorCode);
        }
    }
}
