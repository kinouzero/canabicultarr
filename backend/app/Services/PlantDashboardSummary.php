<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class PlantDashboardSummary
{
    // Apply attention before card ranking; status filtering stays out of global totals.
    public function apply(Builder $query, bool $includeSummary, bool $dueOnly): ?array
    {
        $now = now()->toImmutable();
        $asOf = $now->startOfSecond();
        if (! $includeSummary && ! $dueOnly) {
            return null;
        }
        $watering = app(PlantWateringSelection::class)->due($query, $now);
        if ($dueOnly) {
            $query->where(function ($actions) use ($watering, $asOf) {
                $actions->where(fn ($lifecycle) => $lifecycle->where('phase', '!=', 'planned')->where('phase_due_at', '<=', $asOf))
                    ->orWhereIn('plants.id', $watering);
            });
        }
        if (! $includeSummary) {
            return null;
        }
        $aggregate = (clone $query)->reorder()->toBase();
        if ($dueOnly) {
            $aggregate->selectRaw('COUNT(*) AS actions_due');
        } else {
            $aggregate->leftJoinSub($watering, 'due_watering', 'due_watering.id', '=', 'plants.id')
                ->selectRaw('COUNT(CASE WHEN (phase <> ? AND phase_due_at <= ?) OR due_watering.id IS NOT NULL THEN 1 END) AS actions_due', ['planned', $asOf]);
        }
        $counts = $aggregate->selectRaw(
            'COUNT(CASE WHEN phase <> ? THEN 1 END) AS plants, COUNT(CASE WHEN phase = ? THEN 1 END) AS planned, COUNT(DISTINCT strain_id) AS strains',
            ['planned', 'planned']
        )->first();

        return ['plants' => (int) $counts->plants, 'planned' => (int) $counts->planned,
            'due' => (int) $counts->actions_due, 'strains' => (int) $counts->strains];
    }
}
