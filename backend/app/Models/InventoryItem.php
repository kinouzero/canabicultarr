<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class InventoryItem extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'sku', 'description', 'type', 'unit', 'decimal_places', 'default_dose_per_liter', 'capacity_liters', 'is_archived'];

    protected $hidden = ['sku_key', 'search_text'];

    protected $attributes = ['type' => 'other', 'total_units' => 0, 'version' => 1, 'is_archived' => false, 'unit_locked' => false];

    protected function casts(): array
    {
        return ['default_dose_per_liter' => 'decimal:3', 'capacity_liters' => 'decimal:3', 'decimal_places' => 'integer', 'total_units' => 'integer', 'threshold_units' => 'integer', 'version' => 'integer', 'is_archived' => 'boolean', 'unit_locked' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (InventoryItem $item) {
            $item->name = trim($item->name);
            $item->sku = trim($item->sku ?? '') ?: null;
            $item->sku_key = $item->sku === null ? null : Str::lower($item->sku);
            $item->search_text = Str::lower($item->name."\n".$item->sku."\n".$item->description);
        });
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
