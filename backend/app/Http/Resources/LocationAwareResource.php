<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

abstract class LocationAwareResource extends JsonResource
{
    protected static function newCollection($resource)
    {
        return new LocationAwareResourceCollection($resource, static::class);
    }
}
