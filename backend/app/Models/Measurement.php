<?php

namespace App\Models;

use App\Enums\MeasurementQuality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Measurement extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $with = ['assignmentContext'];

    public function assignmentContext()
    {
        return $this->belongsTo(AssetContext::class, 'asset_context_id');
    }

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $attributes = ['quality' => 'unknown'];

    public function scopeUsable(Builder $query): void
    {
        $query->whereIn('measurements.quality', MeasurementQuality::USABLE);
    }

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'location_snapshot' => 'array', 'provenance_snapshot' => 'array', 'value' => 'decimal:9'];
    }
}
