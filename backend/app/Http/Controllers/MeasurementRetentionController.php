<?php

namespace App\Http\Controllers;

use App\Http\Requests\MeasurementRetentionRequest;
use App\Models\MeasurementPurgeRun;
use App\Services\MeasurementRetention;

class MeasurementRetentionController extends Controller
{
    public function show(MeasurementRetentionRequest $request, MeasurementRetention $retention): array
    {
        return ['data' => $retention->show($request->user())];
    }

    public function preview(MeasurementRetentionRequest $request, MeasurementRetention $retention): array
    {
        return ['data' => $retention->preview($request->user(), $request->validated())];
    }

    public function confirm(MeasurementRetentionRequest $request, MeasurementRetention $retention)
    {
        $run = $retention->confirm($request->user(), $request->validated('token'));

        return response()->json(['data' => $retention->runPayload($run)], 202);
    }

    public function disable(MeasurementRetentionRequest $request, MeasurementRetention $retention): array
    {
        $retention->disable($request->user(), $request->validated('version'));

        return ['data' => $retention->show($request->user())];
    }

    public function cancel(MeasurementRetentionRequest $request, MeasurementPurgeRun $run, MeasurementRetention $retention): array
    {
        $retention->cancel($request->user(), $run);

        return ['data' => $retention->show($request->user())];
    }
}
