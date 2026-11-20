<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Plant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class MobileWateringRecipes
{
    public function read(Collection $plants, array $stages, FertilizerSchedule $schedule): array
    {
        $now = CarbonImmutable::now('UTC');
        $recipes = $plants->filter(fn (Plant $plant) => $stages[$plant->phase]['watering'] ?? false)
            ->mapWithKeys(fn (Plant $plant) => [$plant->id => $schedule->forPlant($plant, $now)])
            ->filter(fn (array $recipe) => $recipe['products'] !== []);
        $ids = $recipes->flatMap(fn (array $recipe) => array_column($recipe['products'], 'inventory_item_id'))->unique();
        $items = InventoryItem::whereIn('id', $ids)->where('type', 'fertilizer')->where('is_archived', false)
            ->with(['balances' => fn ($query) => $query->where('quantity_units', '>', 0)
                ->with('storageLocation:id,name')->orderBy('id')->limit(5)])
            ->get(['id', 'name', 'unit'])->keyBy('id');

        return $recipes->map(function (array $recipe) use ($items, $now) {
            $products = collect($recipe['products'])->map(function (array $entry) use ($items) {
                $item = $items->get($entry['inventory_item_id']);
                if (! $item || $item->balances->isEmpty()) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'dose' => $entry['dose_per_liter'],
                    'balances' => $item->balances->map(fn ($balance) => ['id' => $balance->id, 'name' => $balance->storageLocation->name])->all(),
                ];
            })->filter()->values()->all();
            $changes = collect($recipe['periods'])->flatMap(fn (array $period) => [$period['startsAt'], $period['endsAt']])
                ->push($recipe['flushStartsAt'])->filter(fn (?string $date) => $date && CarbonImmutable::parse($date)->isAfter($now))->sort();

            return ['products' => $products, 'validUntil' => $changes->first()];
        })->filter(fn (array $recipe) => $recipe['products'] !== [])->all();
    }
}
