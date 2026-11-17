<?php

namespace App\Http\Resources;

use App\Services\LocationAccess;
use Illuminate\Http\Request;

class LocationOptionResource extends LocationAwareResource
{
    public function toArray(Request $request): array
    {
        $access = app(LocationAccess::class);
        $site = ['id' => $this->site_id, 'name' => $this->site_name];
        $room = $this->room_id ? ['id' => $this->room_id, 'name' => $this->room_name, 'code' => $this->room_code] : null;
        $zone = $this->zone_id ? ['id' => $this->zone_id, 'name' => $this->zone_name, 'code' => $this->zone_code] : null;

        return [
            'id' => $this->kind.':'.$this->id,
            'name' => implode(' / ', array_filter([$site['name'], $room['name'] ?? null, $zone['name'] ?? null])),
            'detail' => implode(' / ', array_filter([$room['code'] ?? null, $zone['code'] ?? null], fn ($code) => $code !== null)), 'disabled' => ! $access->allows($request->user(), ['type' => $this->kind, 'id' => $this->id]),
            'context' => ['type' => $this->kind, 'id' => $this->id, 'site' => $site, 'room' => $room, 'zone' => $zone],
            'ancestorSelection' => [
                'site' => $access->allows($request->user(), ['type' => 'site', 'id' => $this->site_id]),
                'room' => $room && $access->allows($request->user(), ['type' => 'room', 'id' => $this->room_id]),
            ],
        ];
    }
}
