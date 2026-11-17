<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'recordedAt' => $this->recorded_at->toISOString(),
            'editor' => ['id' => $this->editor_uuid, 'name' => $this->editor?->name], 'snapshot' => $this->snapshot];
    }
}
