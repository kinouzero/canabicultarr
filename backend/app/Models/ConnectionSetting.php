<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectionSetting extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected $hidden = ['values'];

    protected function casts(): array
    {
        return ['values' => 'encrypted:array', 'version' => 'integer'];
    }
}
