<?php

namespace App\Models\Concerns;

use App\Models\Room;
use App\Models\Site;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasAssetLocation
{
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function locationDetails(): ?array
    {
        $zone = $this->zone;
        $room = $zone?->room ?? $this->room;
        $site = $room?->site ?? $this->site;
        if (! $site) {
            return null;
        }
        $ref = fn ($record) => $record ? ['id' => $record->id, 'name' => $record->name] : null;

        return [
            'type' => $zone ? 'zone' : ($room ? 'room' : 'site'),
            'id' => ($zone ?? $room ?? $site)->id,
            'site' => $ref($site), 'room' => $ref($room), 'zone' => $ref($zone),
        ];
    }

    public static function locationRelations(): array
    {
        return ['site', 'room.site', 'zone.room.site'];
    }
}
