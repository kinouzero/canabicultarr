<?php

use App\Http\Controllers\HttpSourceController;
use App\Http\Middleware\AuthenticateHttpSource;
use App\Http\Middleware\PreventAuthCaching;
use Illuminate\Support\Facades\Route;

Route::post('ingestion/http/{source}', [HttpSourceController::class, 'ingest'])
    ->whereUuid('source')
    ->middleware([PreventAuthCaching::class, 'throttle:http-ingestion-ip', AuthenticateHttpSource::class]);
