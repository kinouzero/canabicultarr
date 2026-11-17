<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['email_enabled' => 'boolean', 'push_enabled' => 'boolean', 'version' => 'integer'];
    }
}
