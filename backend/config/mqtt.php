<?php

return [
    'enabled' => (bool) env('MQTT_ENABLED', false),
    'host' => env('MQTT_HOST', ''),
    'port' => (int) env('MQTT_PORT', 8883),
    'tls' => (bool) env('MQTT_TLS', true),
    'username' => env('MQTT_USERNAME'),
    'password' => env('MQTT_PASSWORD'),
    'ca_file' => env('MQTT_CA_FILE'),
];
