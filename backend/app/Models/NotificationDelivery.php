<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    use HasUuids;

    protected $hidden = ['payload', 'recipient_key', 'lease_token'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'settings_version' => 'integer', 'preference_version' => 'integer', 'user_version' => 'integer', 'subscription_version' => 'integer', 'attempts' => 'integer', 'next_attempt_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
