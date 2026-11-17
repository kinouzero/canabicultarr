<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MeasurementType extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'unit', 'decimal_places', 'description', 'nature'];

    protected $hidden = ['name_key'];

    protected $attributes = ['decimal_places' => 2, 'nature' => 'other'];

    protected function casts(): array
    {
        return ['decimal_places' => 'integer', 'nature' => 'string'];
    }

    protected static function booted(): void
    {
        static::saving(function (MeasurementType $type) {
            $type->code = Str::lower(trim($type->code));
            $type->name = trim($type->name);
            $type->name_key = Str::lower($type->name);
        });
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(Sensor::class);
    }
}
