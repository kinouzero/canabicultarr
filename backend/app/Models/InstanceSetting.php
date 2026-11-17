<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstanceSetting extends Model
{
    public $incrementing = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'genetics_colors' => 'array', 'theme' => 'array'];
    }
}
