<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class IntegrationChannelFilters
{
    public static function rules(int $maximum = 160): array
    {
        return ['search' => ['nullable', 'string', 'max:'.$maximum], 'state' => ['nullable', 'in:enabled,disabled']];
    }

    public static function apply(Builder $query, array $criteria, ?string $referenceColumn = null): Builder
    {
        if (isset($criteria['state'])) {
            $query->where('is_enabled', $criteria['state'] === 'enabled');
        }

        $pattern = SensorAdministration::searchPattern($criteria['search'] ?? '');
        if ($pattern !== null) {
            $query->where(function (Builder $match) use ($pattern, $referenceColumn) {
                $match->whereHas('sensor', fn (Builder $sensor) => SensorAdministration::matchSearch($sensor, $pattern));
                if ($referenceColumn !== null) {
                    $match->orWhereRaw('LOWER('.$referenceColumn.") LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        return $query;
    }
}
