<?php

namespace App\Http\Controllers;

use App\Http\Requests\MqttSourceRequest;
use App\Http\Resources\MqttSourceResource;
use App\Models\MqttMeasurementSource;
use App\Services\MqttConfiguration;
use App\Services\MqttSourceAdministration;

class MqttSourceController extends Controller
{
    public function status(MqttSourceRequest $request, MqttConfiguration $configuration)
    {
        return ['data' => ['configured' => $configuration->ready()]];
    }

    public function destroy(MqttSourceRequest $request, MqttMeasurementSource $source, MqttSourceAdministration $sources)
    {
        $sources->save($request->user(), $request->validated(), $source, remove: true);

        return response()->noContent();
    }

    public function index(MqttSourceRequest $request, MqttSourceAdministration $sources)
    {
        return MqttSourceResource::collection($sources->listing($request->user(), $request->validated()));
    }

    public function store(MqttSourceRequest $request, MqttSourceAdministration $sources)
    {
        return (new MqttSourceResource($sources->save($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function update(MqttSourceRequest $request, MqttMeasurementSource $source, MqttSourceAdministration $sources)
    {
        return new MqttSourceResource($sources->save($request->user(), $request->validated(), $source));
    }
}
