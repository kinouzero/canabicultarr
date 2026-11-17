<?php

namespace App\Services;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class CollectionSorting
{
    public static function rules(array $columns): array
    {
        $options = [];
        foreach (array_keys($columns) as $field) {
            $options[] = $field.'_asc';
            $options[] = $field.'_desc';
        }

        return ['sometimes', Rule::in($options)];
    }

    public static function apply(EloquentBuilder|Builder $query, ?string $sort, array $columns, string $default = 'name_asc'): EloquentBuilder|Builder
    {
        if (! preg_match('/^(.+)_(asc|desc)$/', $sort ?? $default, $parts) || ! isset($columns[$parts[1]])) {
            throw new InvalidArgumentException('Unsupported collection sort.');
        }
        $column = $columns[$parts[1]];
        $grammar = $query->getGrammar();
        $sql = $column instanceof Expression ? $column->getValue($grammar) : $grammar->wrap($column);
        $query->reorder()->orderByRaw($sql.' '.$parts[2].' NULLS LAST');
        if (isset($columns['name']) && $parts[1] !== 'name') {
            $query->orderBy($columns['name']);
        }

        return $query->orderBy('id');
    }
}
