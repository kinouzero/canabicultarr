<?php

return ['events_enabled' => env('HOME_ASSISTANT_EVENTS_ENABLED', false), 'sync_enabled' => env('HOME_ASSISTANT_SYNC_ENABLED', true), 'sync_interval_seconds' => env('HOME_ASSISTANT_SYNC_INTERVAL_SECONDS', 60), 'url' => env('HOME_ASSISTANT_URL', ''), 'token' => env('HOME_ASSISTANT_TOKEN', '')];
