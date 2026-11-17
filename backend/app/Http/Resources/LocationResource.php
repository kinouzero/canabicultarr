<?php

namespace App\Http\Resources;

use App\Models\Site;
use App\Services\LocationAccess;
use Illuminate\Http\Request;

class LocationResource extends LocationAwareResource
{
    public function toArray(Request $request): array
    {
        $relation = $this->resource->childRelation();
        $accessible = app(LocationAccess::class)->record($request->user(), $this->resource);
        $photo = $accessible ? $this->photoDocument : null;
        $photo = $photo && ! $photo->is_archived && $photo->format === 'image' && $request->user()->can('view', $photo) ? $photo : null;

        return [
            'id' => $this->id,
            'kind' => $this->resource->kind(),
            'name' => $this->name,
            'code' => $this->resource instanceof Site ? null : $this->code,
            'photoUrl' => $photo ? '/api/v1/documents/'.$photo->id.'/preview?version='.$photo->version : null,
            'hasPhoto' => $photo !== null,
            'description' => $accessible ? $this->description : null,
            'wateringAlertDays' => $accessible ? $this->watering_alert_days : null,
            'canSelect' => $accessible,
            'canManage' => $request->user()->can('update', $this->resource),
            'parentId' => $this->site_id ?? $this->room_id,
            'childrenCount' => $relation ? (int) $this->resource->getAttribute($relation.'_count') : 0,
        ];
    }
}
