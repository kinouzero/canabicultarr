<?php

namespace App\Http\Resources;

use App\Rules\StockQuantity;
use App\Services\InventoryLocations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $quantity = fn ($value) => $value === null ? null : StockQuantity::format($value);

        return ['id' => $this->id, 'requestId' => $this->request_id, 'version' => $this->version, 'kind' => $this->kind, 'quantity' => $quantity($this->quantity_units), 'reason' => $this->reason, 'itemName' => $this->item_name, 'unit' => $this->unit, 'source' => $this->source_snapshot, 'destination' => $this->destination_snapshot,
            'sourceBefore' => $quantity($this->source_before), 'sourceAfter' => $quantity($this->source_after), 'destinationBefore' => $quantity($this->destination_before), 'destinationAfter' => $quantity($this->destination_after), 'totalAfter' => app(InventoryLocations::class)->restricted($request->user()) ? null : $quantity($this->total_after), 'recordedAt' => $this->recorded_at->toISOString(), 'actor' => ['id' => $this->actor_uuid, 'name' => $this->actor?->name]];
    }
}
