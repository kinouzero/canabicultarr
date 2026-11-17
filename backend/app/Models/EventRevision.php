<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRevision extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array', 'recorded_at' => 'immutable_datetime'];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
