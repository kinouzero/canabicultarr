<?php

namespace App\Models;

use App\Services\AssetContexts;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PlantDevice extends Pivot
{
    public $timestamps = false;

    protected static function booted(): void
    {
        $capture = fn (PlantDevice $link) => app(AssetContexts::class)->capture($link->asset_id);
        static::created($capture);
        static::deleted($capture);
    }
}
