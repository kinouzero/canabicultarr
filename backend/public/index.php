<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Check maintenance state for the selected instance before Laravel boots.
$desktopData = getenv('CANABICULTARR_DESKTOP_DATA_DIR');
$storage = $desktopData !== false && $desktopData !== '' ? $desktopData.'/storage' : __DIR__.'/../storage';
if (file_exists($maintenance = $storage.'/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
