<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class PlantEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['container' => $this->snapshot['container'] ?? null, 'substrates' => $this->snapshot['substrates'] ?? [], 'harvestWeightGrams' => $this->snapshot['harvest_weight_grams'] ?? null, 'attachments' => $this->documents->filter(fn ($document) => Gate::forUser($request->user())->allows('view', $document))->map(fn ($document) => ['id' => $document->id, 'title' => $document->title, 'originalName' => $document->original_name, 'mediaType' => $document->media_type, 'sizeBytes' => $document->size_bytes])->values(), 'maintenance' => $this->snapshot['maintenance'] ?? null, 'id' => $this->id, 'kind' => $this->kind, 'fromPhase' => $this->from_phase, 'toPhase' => $this->to_phase, 'comment' => $this->comment, 'watering' => $this->kind === 'watered' ? ['pump' => $this->snapshot['watering']['pump'] ?? null, 'withProducts' => $this->snapshot['watering']['with_products'], 'duringFlush' => $this->snapshot['watering']['during_flush'], 'waterLiters' => $this->snapshot['watering']['water_liters'] ?? null, 'fertilizers' => $this->snapshot['watering']['fertilizers'] ?? []] : null, 'actorId' => $this->actor_uuid, 'occurredAt' => $this->occurred_at->toISOString(), 'recordedAt' => $this->recorded_at->toISOString(), 'snapshot' => $this->snapshot, 'fields' => $this->fields];
    }
}
