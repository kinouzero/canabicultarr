<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Location
{
    public function kind(): string
    {
        return 'site';
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function childRelation(): ?string
    {
        return 'rooms';
    }
}
