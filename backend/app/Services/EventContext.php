<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetContext;
use App\Models\Room;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Zone;

class EventContext
{
    public function resolve(?array $subject): array
    {
        $context = ['site_id' => null, 'room_id' => null, 'zone_id' => null, 'asset_id' => null, 'sensor_id' => null, 'subject_kind' => null, 'subject_snapshot' => null];
        if ($subject === null) {
            return $context;
        }
        $type = $subject['type'];
        $id = $subject['id'];
        $record = match ($type) {
            'site' => Site::findOrFail($id), 'room' => Room::with('site')->findOrFail($id),
            'zone' => Zone::with('room.site')->findOrFail($id),
            'asset' => Asset::with(Asset::locationRelations())->findOrFail($id),
            'sensor' => Sensor::with(SensorAdministration::relations())->findOrFail($id),
        };
        $location = match ($type) {
            'site', 'room', 'zone' => $this->location($record),
            'asset' => $record->locationDetails(), 'sensor' => $record->device->locationDetails(),
        };
        if ($location) {
            $context['site_id'] = $location['site']['id'];
            $context['room_id'] = $location['room']['id'] ?? null;
            $context['zone_id'] = $location['zone']['id'] ?? null;
        }
        if ($type === 'asset') {
            $context['asset_id'] = $record->id;
        }
        if ($type === 'sensor') {
            $context['sensor_id'] = $record->id;
            $context['asset_id'] = $record->asset_id;
        }
        $context['subject_kind'] = $type;
        $hardware = $type === 'asset' ? $record : ($type === 'sensor' ? $record->device : null);
        $hardwareContext = $hardware ? AssetContext::where('asset_id', $hardware->id)->orderByDesc('effective_at')->orderByDesc('id')->value('id') : null;
        $context['subject_snapshot'] = ['type' => $type, 'id' => $record->id, 'name' => $record->name,
            'location' => $location, ...($hardwareContext ? ['assetContextId' => $hardwareContext, 'locations' => $hardware->locationList()] : []), 'deviceName' => $type === 'sensor' ? $record->device->name : null];

        return $context;
    }

    private function location(Site|Room|Zone $record): array
    {
        $zone = $record instanceof Zone ? $record : null;
        $room = $zone?->room ?? ($record instanceof Room ? $record : null);
        $site = $room?->site ?? $record;
        $ref = fn ($value) => $value ? ['id' => $value->id, 'name' => $value->name] : null;

        return ['type' => $record->kind(), 'id' => $record->id, 'site' => $ref($site), 'room' => $ref($room), 'zone' => $ref($zone)];
    }
}
