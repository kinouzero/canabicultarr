<?php

// Fictional provider, callable only from the isolated browser-test backend.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== '/var/www/html/storage/e2e.sqlite') {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer e2e-home-assistant-token') {
    http_response_code(401);
    echo '{}';
    exit;
}
$timeFile = '/tmp/canabicultarr-ha-e2e-time';
if (! is_file($timeFile)) {
    file_put_contents($timeFile, gmdate('Y-m-d\\TH:i:s\\Z'));
}
$reported = file_get_contents($timeFile);
$states = [];
foreach (['sensor.e2e_temperature' => ['E2E Temperature', '°C', 'temperature', '21.5'], 'sensor.e2e_humidity' => ['E2E Humidity', '%', 'humidity', '48.25']] as $id => [$name,$unit,$class,$value]) {
    $states[$id] = ['entity_id' => $id, 'state' => $value, 'attributes' => ['friendly_name' => $name, 'unit_of_measurement' => $unit, 'device_class' => $class], 'last_reported' => $reported, 'last_updated' => $reported];
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/') {
    echo json_encode(['message' => 'API running']);
} elseif ($path === '/api/states') {
    echo json_encode(array_values($states));
} elseif ($path === '/api/template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    preg_match_all('/"((?:sensor|number|input_number)\.[a-z0-9_]+)"/', $input['template'] ?? '', $matches);
    $result = [];
    foreach (array_unique($matches[1]) as $id) {
        if (isset($states[$id])) {
            $result[] = ['entity_id' => $id, 'device_id' => 'e2e-physical-device', 'name' => 'E2E Environmental Device', 'manufacturer' => 'Test Manufacturer', 'model' => 'Test Model'];
        }
    }
    echo json_encode($result);
} elseif (str_starts_with($path, '/api/states/') && isset($states[substr($path, 12)])) {
    echo json_encode($states[substr($path, 12)]);
} else {
    http_response_code(404);
    echo '{}';
}
