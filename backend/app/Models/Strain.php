<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Strain extends Model
{
    use HasUuids;

    protected $fillable = ['theoretical_yield', 'name', 'source_url', 'classification', 'genetics', 'lineage', 'thc', 'cbd', 'aroma', 'reported_effects', 'notes', 'duration_overrides', 'is_archived', 'seed_inventory_item_id'];

    protected $attributes = ['version' => 1, 'is_archived' => false];

    protected static function booted(): void
    {
        static::saving(function (Strain $record) {
            $record->name = trim($record->name);
            $record->name_key = Str::lower($record->name);
        });
    }

    public function seedInventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'seed_inventory_item_id');
    }

    public function scopeWithSeedStock(Builder $query): void
    {
        $query->with(['seedInventoryItem' => fn ($items) => $items->withSum('balances', 'quantity_units')]);
    }

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'strain_photos')->withTimestamps();
    }

    protected function casts(): array
    {
        return ['theoretical_yield' => 'decimal:2', 'duration_overrides' => 'array', 'version' => 'integer', 'is_archived' => 'boolean'];
    }
}
