<?php

$root = realpath(__DIR__.'/../../frontend/dist');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if (($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:'.$_SERVER['SERVER_PORT']) {
    http_response_code(400);

    return true;
}

$proof = $_SERVER['HTTP_X_CANABICULTARR_LAN_TOKEN'] ?? null;
$client = $_SERVER['HTTP_X_CANABICULTARR_LAN_CLIENT'] ?? '';
unset($_SERVER['HTTP_X_CANABICULTARR_LAN_TOKEN'], $_SERVER['HTTP_X_CANABICULTARR_LAN_CLIENT']);
if ($proof !== null) {
    $token = getenv('CANABICULTARR_DESKTOP_PROXY_TOKEN');
    $origin = getenv('CANABICULTARR_DESKTOP_LAN_ORIGIN');
    if ($token === false || $origin === false || ! hash_equals($token, $proof) || ! filter_var($client, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        http_response_code(400);

        return true;
    }
    $public = parse_url($origin);
    $_SERVER['HTTP_HOST'] = $public['host'].':'.$public['port'];
    $_SERVER['SERVER_NAME'] = $public['host'];
    $_SERVER['SERVER_PORT'] = $public['port'];
    $_SERVER['REMOTE_ADDR'] = $client;
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SESSION_SECURE_COOKIE'] = $_ENV['SESSION_SECURE_COOKIE'] = 'true';
    // The browser wizard provisions the first account on the host computer.
    $route = preg_replace('~/+~', '/', $path);
    if (preg_match('~^/api/v1/setup(?:/|$)~i', rtrim($route, '/'))
        && (rtrim(strtolower($route), '/') !== '/api/v1/setup' || ! in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true))) {
        http_response_code(404);

        return true;
    }
}

$runId = getenv('CANABICULTARR_DESKTOP_RUN_ID');
if (is_string($runId) && preg_match('/^[a-f0-9-]{36}$/D', $runId)) {
    header('X-Canabicultarr-Desktop-Run: '.$runId);
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');

if (preg_match('~^/(api(?:/|$)|up$)~', $path)) {
    $index = __DIR__.'/../../backend/public/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $index;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';

    require $index;

    return true;
}

// Only the compiled frontend is a web document root.
$file = str_contains($path, "\0") || str_contains($path, '/.') ? false : realpath($root.$path);
if ($file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file) && ! preg_match('/\.php\d*$/i', $file)) {
    return false;
}

if (str_contains($path, '/.') || str_starts_with($path, '/assets/') || pathinfo($path, PATHINFO_EXTENSION) !== '') {
    http_response_code(404);

    return true;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache');
readfile($root.'/index.html');

return true;
