<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EventEntryResource extends LocationAwareResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, ...$this->resource->snapshot(), 'version' => $this->version,
            'author' => ['id' => $this->author_uuid, 'name' => $this->author?->name],
            'createdAt' => $this->created_at->toISOString(), 'updatedAt' => $this->updated_at->toISOString(),
            'canEdit' => $request->user()->can('update', $this->resource)];
    }
}
