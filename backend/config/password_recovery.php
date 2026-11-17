<?php

return [
    'enabled' => env('PASSWORD_RECOVERY_ENABLED', false),
    // A trusted public application root, never derived from request headers.
    'url' => env('PASSWORD_RECOVERY_URL', ''),
];
