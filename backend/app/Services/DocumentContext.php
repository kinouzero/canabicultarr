<?php

namespace App\Services;

use App\Models\EventEntry;
use App\Models\Strain;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DocumentContext
{
    public function __construct(private EventContext $context) {}

    public function authorize(User $user, ?array $snapshot): void
    {
        if (($snapshot['type'] ?? null) === 'strain') {
            Gate::forUser($user)->authorize('update', Strain::findOrFail($snapshot['id']));
        } else {
            app(LocationAccess::class)->assertSubject($user, $snapshot);
        }
    }

    public function resolve(?array $subject): array
    {
        if (($subject['type'] ?? null) === 'strain') {
            $strain = Strain::findOrFail($subject['id']);

            return [...$this->context->resolve(null), 'event_entry_id' => null, 'subject_kind' => 'strain',
                'subject_snapshot' => ['type' => 'strain', 'id' => $strain->id, 'name' => $strain->name, 'location' => null]];
        }
        if (($subject['type'] ?? null) !== 'event') {
            return [...$this->context->resolve($subject), 'event_entry_id' => null];
        }
        $event = EventEntry::findOrFail($subject['id']);
        $snapshot = $event->subject_snapshot;

        return ['site_id' => $event->site_id, 'room_id' => $event->room_id, 'zone_id' => $event->zone_id, 'asset_id' => $event->asset_id, 'sensor_id' => $event->sensor_id,
            'event_entry_id' => $event->id, 'subject_kind' => 'event', 'subject_snapshot' => ['type' => 'event', 'id' => $event->id, 'name' => $event->title, 'location' => $snapshot['location'] ?? null, ...array_intersect_key($snapshot ?? [], array_flip(['assetContextId', 'locations'])), 'deviceName' => $snapshot['deviceName'] ?? null]];
    }
}
