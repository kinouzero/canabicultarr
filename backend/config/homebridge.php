<?php

return [
    'events_enabled' => env('HOMEBRIDGE_EVENTS_ENABLED', false), 'sync_enabled' => env('HOMEBRIDGE_SYNC_ENABLED', true),
    'sync_interval_seconds' => env('HOMEBRIDGE_SYNC_INTERVAL_SECONDS', 60),
    'url' => env('HOMEBRIDGE_URL', ''),
    'username' => env('HOMEBRIDGE_USERNAME', ''),
    'password' => env('HOMEBRIDGE_PASSWORD', ''),
];
