<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportExportRequest;
use App\Models\MeasurementReportExport;
use App\Models\Sensor;
use App\Services\MeasurementReportExports;

class ReportExportController extends Controller
{
    public function index(ReportExportRequest $request, Sensor $sensor, MeasurementReportExports $exports)
    {
        return response()->json(['data' => $exports->listing($request->user(), $sensor)])->header('Cache-Control', 'private, no-store');
    }

    public function store(ReportExportRequest $request, Sensor $sensor, MeasurementReportExports $exports)
    {
        return response()->json(['data' => $exports->payload($exports->create($request->user(), $sensor, $request->validated()))], 202)->header('Cache-Control', 'private, no-store');
    }

    public function cancel(ReportExportRequest $request, MeasurementReportExport $export, MeasurementReportExports $exports)
    {
        $exports->cancel($request->user(), $export);

        return response()->noContent();
    }

    public function download(ReportExportRequest $request, MeasurementReportExport $export, MeasurementReportExports $exports)
    {
        $data = $exports->download($request->user(), $export);
        $stream = $data['stream'];

        return response()->streamDownload(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $data['filename'], ['Content-Type' => $data['mime'], 'Content-Length' => (string) $data['bytes'], 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'Referrer-Policy' => 'no-referrer']);
    }
}
