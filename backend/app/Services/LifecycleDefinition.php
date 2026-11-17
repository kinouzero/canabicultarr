<?php

namespace App\Services;

use App\Models\LifecycleSetting;
use App\Models\Plant;
use Illuminate\Validation\ValidationException;

class LifecycleDefinition
{
    public const ICONS = ['seed', 'sprout', 'leaf', 'flower', 'pot', 'scissors', 'harvest', 'hanger', 'rack', 'jar', 'hourglass', 'archive', 'clock', 'water', 'humidity', 'light', 'fan', 'pump', 'sensor', 'box', 'camera', 'journal', 'message', 'bell', 'refresh', 'arrow'];

    public static function defaults(): array
    {
        $phases = ['planned', 'sowing', 'germination', 'growth', 'flowering', 'hanging_drying', 'rack_drying', 'curing', 'maturation', 'archived'];
        $icons = ['clock', 'seed', 'sprout', 'leaf', 'flower', 'hanger', 'rack', 'jar', 'hourglass', 'archive'];
        $actions = ['sow', 'plant', 'repot_growth', 'repot_flowering', 'harvest', 'transfer_to_rack', 'transfer_to_containers', 'start_maturation', 'archive'];
        $actionIcons = ['seed', 'sprout', 'pot', 'pot', 'harvest', 'rack', 'jar', 'hourglass', 'archive'];

        return array_map(fn ($id, $i) => [
            'id' => $id, 'name' => null, 'icon' => $icons[$i],
            'watering' => $i >= 2 && $i <= 4, 'maintenance' => $i >= 2 && $i <= 4,
            'flush' => $id === 'flowering',
            'actions' => $i === 9 ? [] : [[
                'id' => $actions[$i], 'name' => null, 'icon' => $actionIcons[$i],
                'target' => $phases[$i + 1], 'requires_harvest_weight' => $id === 'curing',
            ]],
        ], $phases, array_keys($phases));
    }

    public static function stages(): array
    {
        $request = request();
        if (! $request->attributes->has('lifecycle.stages')) {
            $request->attributes->set('lifecycle.stages', LifecycleSetting::findOrFail(1)->stages);
        }

        return $request->attributes->get('lifecycle.stages');
    }

    public static function ids(): array
    {
        return array_column(self::stages(), 'id');
    }

    public static function stage(string $id): ?array
    {
        return collect(self::stages())->firstWhere('id', $id);
    }

    public static function actions(string $phase): array
    {
        return self::stage($phase)['actions'] ?? [];
    }

    public static function action(string $id): ?array
    {
        foreach (self::stages() as $stage) {
            foreach ($stage['actions'] as $action) {
                if ($action['id'] === $id) {
                    return [...$action, 'from' => $stage['id']];
                }
            }
        }

        return null;
    }

    public static function allowing(string $capability): array
    {
        return array_column(array_filter(self::stages(), fn ($stage) => $stage[$capability]), 'id');
    }

    public static function durationKeys(): array
    {
        return [...array_values(array_diff(self::ids(), ['planned', 'archived'])), 'flush'];
    }

    public static function validate(array $stages, array $durations): void
    {
        $ids = array_column($stages, 'id');
        $fail = fn ($key = 'catalog.validation.workflow') => throw ValidationException::withMessages(['stages' => $key]);
        if (in_array('flush', $ids, true) || $ids[0] !== 'planned' || end($ids) !== 'archived' || count($ids) !== count(array_unique($ids))) {
            $fail();
        }
        $removed = array_diff(self::ids(), $ids);
        if ($removed && Plant::withoutGlobalScopes()->whereIn('phase', $removed)->exists()) {
            $fail('catalog.validation.stageInUse');
        }
        if (array_diff(array_keys($durations), [...array_diff($ids, ['planned', 'archived']), 'flush'])) {
            $fail();
        }
        $actions = [];
        $reserved = ['created', 'updated', 'imported', 'trello_comment', 'matured', 'watered', 'maintained'];
        foreach ($stages as $stage) {
            if (in_array($stage['id'], ['planned', 'archived']) && ($stage['watering'] || $stage['maintenance'] || $stage['flush'])) {
                $fail();
            }
            if ($stage['flush'] && (! $stage['watering'] || ! isset($durations[$stage['id']]))) {
                $fail();
            }
            if ($stage['id'] === 'archived' && $stage['actions']) {
                $fail();
            }
            foreach ($stage['actions'] as $action) {
                if (! in_array($action['target'], $ids, true) || in_array($action['id'], [...$actions, ...$reserved], true)) {
                    $fail();
                }
                $actions[] = $action['id'];
            }
        }
        // Backward and skipping transitions are allowed; every stage must retain a route to archival.
        $canArchive = ['archived'];
        do {
            $previousCount = count($canArchive);
            foreach ($stages as $stage) {
                if (! in_array($stage['id'], $canArchive, true) && array_intersect(array_column($stage['actions'], 'target'), $canArchive)) {
                    $canArchive[] = $stage['id'];
                }
            }
        } while (count($canArchive) > $previousCount);
        if (array_diff($ids, $canArchive)) {
            $fail();
        }
    }
}
