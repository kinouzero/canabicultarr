<?php

return [
    'transport' => env('BLUETOOTH_TRANSPORT', 'auto'),
    'enabled' => (bool) env('BLUETOOTH_ENABLED', false),
    'adapter' => env('BLUETOOTH_ADAPTER', 'hci0'),
    'relay_url' => env('BLUETOOTH_RELAY_URL', ''),
    'relay_token' => env('BLUETOOTH_RELAY_TOKEN', ''),
    'relay_allow_http' => (bool) env('BLUETOOTH_RELAY_ALLOW_HTTP', false),
    'relay_ca_file' => env('BLUETOOTH_RELAY_CA_FILE', ''),
    'python' => '/opt/bluetooth/bin/python',
];
