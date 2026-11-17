<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlantEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_id', 'request_hash'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'fields' => 'array', 'occurred_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }

    public function documents()
    {
        return $this->belongsToMany(Document::class, 'plant_event_documents')->orderBy('documents.id');
    }

    public function plant()
    {
        return $this->belongsTo(Plant::class);
    }
}
