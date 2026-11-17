<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

abstract class Location extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'description', 'watering_alert_days'];

    protected $hidden = ['name_key'];

    protected static function booted(): void
    {
        static::saving(function (Location $location) {
            $location->name = trim($location->name);
            $location->name_key = Str::lower($location->name);
            if ($location instanceof Room || $location instanceof Zone) {
                $location->code = $location->code === null ? strtoupper(substr($location->kind(), 0, 1).'-'.Str::random(12)) : strtoupper(trim($location->code));
            }
        });
    }

    protected function casts(): array
    {
        return ['watering_alert_days' => 'integer'];
    }

    public function photoDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'photo_document_id');
    }

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, $this->kind().'_photos', $this->kind().'_id', 'document_id')->withTimestamps();
    }

    abstract public function kind(): string;

    public function childRelation(): ?string
    {
        return null;
    }
}
