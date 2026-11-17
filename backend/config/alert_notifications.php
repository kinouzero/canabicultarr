<?php

return [
    'webhook' => [
        'url' => env('ALERT_WEBHOOK_URL', ''),
        'allowed_internal' => env('ALERT_WEBHOOK_ALLOWED_INTERNAL', ''),
        'token' => env('ALERT_WEBHOOK_TOKEN', ''),
        'format' => env('ALERT_WEBHOOK_FORMAT', 'generic'),
        'topic' => env('ALERT_WEBHOOK_TOPIC', ''),
        'tag' => env('ALERT_WEBHOOK_APPRISE_TAG', ''),
        'apprise_urls' => env('ALERT_WEBHOOK_APPRISE_URLS', ''),
    ],
    'push' => [
        'public_key' => env('WEB_PUSH_PUBLIC_KEY', ''),
        'private_key' => env('WEB_PUSH_PRIVATE_KEY', ''),
        'subject' => env('WEB_PUSH_SUBJECT', ''),
        'allowed_hosts' => array_filter(array_map('trim', explode(',', env('WEB_PUSH_ALLOWED_HOSTS', 'fcm.googleapis.com,updates.push.services.mozilla.com,*.push.services.mozilla.com,web.push.apple.com,*.notify.windows.com')))),
    ],
];
