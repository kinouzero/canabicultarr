<?php

return [
    'enabled' => env('BACKUP_ENABLED', false),
    'frequency' => env('BACKUP_FREQUENCY', 'daily'),
    'day' => env('BACKUP_DAY', 0),
    'time' => env('BACKUP_TIME', '02:00'),
    'timezone' => env('BACKUP_TIMEZONE', 'UTC'),
    'keep' => env('BACKUP_KEEP', 14),
    'path' => env('BACKUP_PATH', storage_path('app/backups')),
    'recipient' => env('BACKUP_RECIPIENT'),
    'config_file' => env('BACKUP_CONFIG_FILE'),
];
