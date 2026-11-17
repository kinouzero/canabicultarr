<?php

namespace App\Services;

class CollectionPagination
{
    public static function rules(): array
    {
        return ['sometimes', 'integer', 'between:1,100'];
    }

    public static function size(array $criteria, int $default = 20): int
    {
        return max(1, min(100, (int) ($criteria['per_page'] ?? $default)));
    }
}
