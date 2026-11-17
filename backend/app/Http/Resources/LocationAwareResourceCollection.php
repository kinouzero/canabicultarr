<?php

namespace App\Http\Resources;

use App\Services\LocationAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationAwareResourceCollection extends AnonymousResourceCollection
{
    public function toArray(Request $request): array
    {
        app(LocationAccess::class)->prepare($request->user(), $this->collection->map(fn ($resource) => $resource->resource));

        return parent::toArray($request);
    }
}
