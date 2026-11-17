<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetContext extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'effective_at' => 'immutable_datetime'];
    }
}
