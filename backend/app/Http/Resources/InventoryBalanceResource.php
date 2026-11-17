<?php

namespace App\Http\Resources;

use App\Rules\StockQuantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'location' => $this->locationDetails(), 'quantity' => StockQuantity::format($this->quantity_units)];
    }
}
