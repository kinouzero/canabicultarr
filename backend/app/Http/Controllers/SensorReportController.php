<?php

namespace App\Http\Controllers;

use App\Http\Requests\SensorReportRequest;
use App\Http\Resources\SensorResource;
use App\Models\Sensor;
use App\Services\SensorReporting;

class SensorReportController extends Controller
{
    public function show(SensorReportRequest $request, Sensor $sensor, SensorReporting $reports)
    {
        $report = $reports->report($request->user(), $sensor, $request->validated());
        $report['sensor'] = new SensorResource($report['sensor']);

        return ['data' => $report];
    }

    public function export(SensorReportRequest $request, Sensor $sensor, SensorReporting $reports)
    {
        $data = $reports->export($request->user(), $sensor, $request->validated());
        $stream = $data['stream'];

        return response()->streamDownload(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $data['filename'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Length' => (string) $data['size'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'Referrer-Policy' => 'no-referrer']);
    }
}
