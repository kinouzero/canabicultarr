<?php

namespace App\Http\Controllers;

use App\Http\Requests\MeasurementOverviewRequest;
use App\Http\Resources\MeasurementResource;
use App\Http\Resources\SensorResource;
use App\Services\MeasurementOverview;
use App\Services\SensorSwitchControl;

class MeasurementOverviewController extends Controller
{
    public function index(MeasurementOverviewRequest $request, MeasurementOverview $overview)
    {
        $result = $overview->read($request->validated());
        $switches = app(SensorSwitchControl::class)->describe(collect(array_column($result['rows'], 'sensor')));
        foreach ($result['rows'] as $row) {
            $row['sensor']->setRelation('switchControl', $switches[$row['sensor']->id] ?? null);
        }

        return ['data' => array_map(fn ($row) => ['sensor' => new SensorResource($row['sensor']), 'latest' => $row['latest'] ? new MeasurementResource($row['latest']) : null, 'status' => $row['status']], $result['rows']),
            'meta' => $result['meta'], 'summary' => $result['summary'], 'generatedAt' => $result['generatedAt']];
    }
}
