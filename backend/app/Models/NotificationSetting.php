<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    protected $table = 'notification_settings';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['email_enabled' => 'boolean', 'push_enabled' => 'boolean', 'webhook_enabled' => 'boolean', 'include_recovery' => 'boolean', 'version' => 'integer'];
    }
}
