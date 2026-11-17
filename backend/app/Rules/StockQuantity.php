<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StockQuantity implements ValidationRule
{
    public const MAX_UNITS = 999999999999;

    public static function toUnits(string $value): int
    {
        [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }

    public static function format(int $value): string
    {
        return intdiv($value, 1000).'.'.str_pad((string) ($value % 1000), 3, '0', STR_PAD_LEFT);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,3})?$/D', $value)) {
            $fail('inventory.validation.quantityInvalid');
        }
    }
}
