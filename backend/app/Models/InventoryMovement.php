<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['request_hash', 'recorded_by'];

    protected function casts(): array
    {
        return ['quantity_units' => 'integer', 'version' => 'integer', 'source_snapshot' => 'array', 'destination_snapshot' => 'array', 'source_before' => 'integer', 'source_after' => 'integer', 'destination_before' => 'integer', 'destination_after' => 'integer', 'total_after' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
