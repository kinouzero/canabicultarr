<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListSensorsRequest;
use App\Http\Requests\SaveSensorRequest;
use App\Http\Resources\SensorResource;
use App\Models\Sensor;
use App\Services\AssetPowerControl;
use App\Services\SensorAdministration;
use App\Services\SensorSwitchControl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SensorController extends Controller
{
    public function index(ListSensorsRequest $request, SensorAdministration $records)
    {
        return SensorResource::collection($records->search($request->validated()));
    }

    public function show(Request $request, Sensor $sensor)
    {
        Gate::authorize('view', $sensor);

        return new SensorResource($sensor->load(SensorAdministration::relations()));
    }

    public function switchContext(Request $request, Sensor $sensor, SensorSwitchControl $switches)
    {
        $binding = $switches->binding($request->user(), $sensor, true);

        return ['data' => ['isOn' => $binding['isOn']]];
    }

    public function switchCommand(Request $request, Sensor $sensor, AssetPowerControl $commands)
    {
        $data = $request->validate(['is_on' => ['required', 'boolean'], 'request_id' => ['required', 'uuid']]);
        $result = $commands->command($request->user(), $sensor->device, (bool) $data['is_on'], strtolower($data['request_id']), channel: $sensor);

        return ['data' => $result];
    }

    public function store(SaveSensorRequest $request, SensorAdministration $records)
    {
        return (new SensorResource($records->save($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function update(SaveSensorRequest $request, Sensor $sensor, SensorAdministration $records)
    {
        return new SensorResource($records->save($request->user(), $request->validated(), $sensor));
    }
}
