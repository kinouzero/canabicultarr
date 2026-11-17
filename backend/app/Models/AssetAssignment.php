<?php

namespace App\Models;

use App\Models\Concerns\HasAssetLocation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAssignment extends Model
{
    use HasAssetLocation, HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'locations_snapshot' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
