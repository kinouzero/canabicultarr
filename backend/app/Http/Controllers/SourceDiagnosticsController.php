<?php

namespace App\Http\Controllers;

use App\Http\Requests\SourceDiagnosticsRequest;
use App\Services\SourceDiagnostics;

class SourceDiagnosticsController extends Controller
{
    public function index(SourceDiagnosticsRequest $request, SourceDiagnostics $diagnostics)
    {
        return response()->json($diagnostics->read($request->user(), $request->validated()));
    }
}
