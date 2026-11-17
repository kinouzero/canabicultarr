<?php

namespace App\Http\Resources;

use App\Rules\StockQuantity;
use App\Services\InventoryLocations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StrainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['seedInventoryItem' => fn ($items) => $items->withSum('balances', 'quantity_units')]);
        $stock = $this->seedInventoryItem;
        $restricted = app(InventoryLocations::class)->restricted($request->user());
        $quantity = $stock ? ($restricted ? (int) $stock->balances_sum_quantity_units : $stock->total_units) : null;

        return [
            'seedStock' => $stock ? ['id' => $stock->id, 'name' => $stock->name, 'quantity' => StockQuantity::format($quantity), 'isArchived' => $stock->is_archived, 'scoped' => $restricted] : null,
            'theoreticalYield' => $this->theoretical_yield,
            'id' => $this->id, 'name' => $this->name, 'sourceUrl' => $this->source_url,
            'classification' => $this->classification, 'genetics' => $this->genetics,
            'lineage' => $this->lineage, 'thc' => $this->thc, 'cbd' => $this->cbd,
            'aroma' => $this->aroma, 'reportedEffects' => $this->reported_effects, 'notes' => $this->notes,
            'durationOverrides' => (object) ($this->duration_overrides ?? []),
            'isArchived' => $this->is_archived, 'version' => $this->version,
            'canManage' => $request->user()->can('update', $this->resource),
        ];
    }
}
