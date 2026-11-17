<?php

namespace App\Http\Resources;

use App\Rules\StockQuantity;
use App\Services\InventoryLocations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $restricted = app(InventoryLocations::class)->restricted($request->user());
        $total = $restricted ? (int) ($this->resource->balances_sum_quantity_units ?? $this->resource->balances()->sum('quantity_units')) : $this->total_units;

        return ['canEditCatalog' => ! $restricted && $request->user()->can('update', $this->resource), 'id' => $this->id, 'name' => $this->name, 'sku' => $this->sku, 'description' => $this->description, 'type' => $this->type, 'defaultDosePerLiter' => $this->default_dose_per_liter, 'capacityLiters' => $this->capacity_liters, 'unit' => $this->unit, 'decimalPlaces' => $this->decimal_places, 'unitLocked' => $this->unit_locked, 'totalQuantity' => StockQuantity::format($total), 'threshold' => $restricted || $this->threshold_units === null ? null : StockQuantity::format($this->threshold_units), 'isLowStock' => ! $restricted && $this->threshold_units !== null && $this->total_units <= $this->threshold_units, 'isArchived' => $this->is_archived, 'version' => $this->version, 'canManage' => $request->user()->can('update', $this->resource)];
    }
}
