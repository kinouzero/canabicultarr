<?php

namespace App\Services;

use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Gate;

class CultureOverview
{
    private function authorize(User $actor): void
    {
        $current = User::find($actor->id);
        if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }
        Gate::forUser($current)->authorize('dashboard.view');
    }

    public function read(User $actor, array $filters): array
    {
        $this->authorize($actor);

        return app(LocationAccess::class)->run($actor, function () use ($actor, $filters) {
            $now = CarbonImmutable::now('UTC');
            $days = $filters['days'] ?? 7;
            $query = Plant::query();
            if (! empty($filters['site_id'])) {
                $site = Site::findOrFail($filters['site_id']);
                $query->where(fn ($q) => $q->where('site_id', $site->id)->orWhereHas('room', fn ($r) => $r->where('site_id', $site->id))->orWhereHas('zone.room', fn ($r) => $r->where('site_id', $site->id)));
            }
            $counts = ['total' => 0, 'active' => 0, 'planned' => 0, 'archived' => 0, 'wateringDue' => 0, 'lifecycleDue' => 0, 'due' => 0, 'harvested' => 0, 'harvestWeightGrams' => '0.00'];
            $phases = array_fill_keys(LifecycleDefinition::ids(), 0);
            $locations = [];
            $strains = [];
            $upcoming = [];
            (clone $query)->with([...Plant::locationRelations(), 'strain'])->chunkById(250, function ($plants) use (&$counts, &$phases, &$locations, &$strains, &$upcoming, $now) {
                $watering = app(PlantWateringNeeds::class);
                $watering->prepare($plants);
                foreach ($plants as $plant) {
                    $counts['total']++;
                    $phases[$plant->phase] = ($phases[$plant->phase] ?? 0) + 1;
                    $active = ! in_array($plant->phase, ['planned', 'archived'], true);
                    $counts[$active ? 'active' : $plant->phase]++;
                    $location = $plant->locationDetails();
                    $key = $location ? $location['type'].':'.$location['id'] : 'unassigned';
                    $locations[$key] ??= ['key' => $key, 'location' => $location, 'active' => 0, 'planned' => 0, 'due' => 0];
                    $locations[$key]['active'] += (int) $active;
                    $locations[$key]['planned'] += (int) ($plant->phase === 'planned');
                    $strains[$plant->strain_id] ??= ['id' => $plant->strain_id, 'name' => $plant->strain->name, 'active' => 0, 'harvestWeightGrams' => '0.00', 'harvested' => 0];
                    $strains[$plant->strain_id]['active'] += (int) $active;
                    if ($plant->harvest_weight_grams !== null) {
                        $counts['harvested']++;
                        $counts['harvestWeightGrams'] = bcadd($counts['harvestWeightGrams'], $plant->harvest_weight_grams, 2);
                        $strains[$plant->strain_id]['harvested']++;
                        $strains[$plant->strain_id]['harvestWeightGrams'] = bcadd($strains[$plant->strain_id]['harvestWeightGrams'], $plant->harvest_weight_grams, 2);
                    }
                    $waterDue = $active && $watering->state($plant)['needed'];
                    $lifecycleDue = $active && $plant->phase_due_at?->lessThanOrEqualTo($now);
                    $counts['wateringDue'] += (int) $waterDue;
                    $counts['lifecycleDue'] += (int) $lifecycleDue;
                    $counts['due'] += (int) ($waterDue || $lifecycleDue);
                    $locations[$key]['due'] += (int) ($waterDue || $lifecycleDue);
                    if ($active && $plant->phase_due_at && $plant->phase_due_at->lessThanOrEqualTo($now->addDays(7))) {
                        $upcoming[] = ['id' => $plant->id, 'name' => $plant->displayName(), 'phase' => $plant->phase, 'dueAt' => $plant->phase_due_at->toISOString(), 'location' => $location];
                        usort($upcoming, fn ($a, $b) => [$a['dueAt'], $a['id']] <=> [$b['dueAt'], $b['id']]);
                        $upcoming = array_slice($upcoming, 0, 12);
                    }
                }
            });
            $activity = [];
            $from = $now->subDays($days - 1)->startOfDay();
            for ($cursor = $from; $cursor->lessThanOrEqualTo($now); $cursor = $cursor->addDay()) {
                $activity[$cursor->format('Y-m-d')] = ['day' => $cursor->format('Y-m-d'), 'waterings' => 0, 'maintenance' => 0, 'transitions' => 0];
            }
            PlantEvent::whereIn('plant_id', (clone $query)->select('plants.id'))->whereBetween('occurred_at', [$from, $now])->select(['id', 'kind', 'occurred_at'])->chunkById(1000, function ($events) use (&$activity) {
                foreach ($events as $event) {
                    $key = $event->occurred_at->format('Y-m-d');
                    $field = match ($event->kind) {
                        'watered' => 'waterings', 'maintained' => 'maintenance', 'created', 'updated' => null, default => 'transitions'
                    };
                    if ($field && isset($activity[$key])) {
                        $activity[$key][$field]++;
                    }
                }
            });
            $this->authorize($actor);

            return ['generatedAt' => $now->toISOString(), 'days' => $days, 'plants' => [...$counts, 'byPhase' => $phases],
                'locations' => collect($locations)->filter(fn ($row) => $row['active'] + $row['planned'] > 0)->sortByDesc('active')->values()->all(),
                'strains' => collect($strains)->sortByDesc('active')->values()->all(), 'upcoming' => $upcoming, 'activity' => array_values($activity)];
        });
    }
}
