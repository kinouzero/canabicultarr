<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity_units' => 'integer'];
    }

    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    public static function locationRelations(): array
    {
        return ['storageLocation'];
    }

    public function locationDetails(): array
    {
        return ['id' => $this->storage_location_id, 'name' => $this->storageLocation->name];
    }
}
