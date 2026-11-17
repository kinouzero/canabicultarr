<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListMeasurementTypesRequest;
use App\Http\Requests\SaveMeasurementTypeRequest;
use App\Http\Resources\MeasurementTypeResource;
use App\Models\MeasurementType;
use App\Services\MeasurementTypeAdministration;

class MeasurementTypeController extends Controller
{
    public function index(ListMeasurementTypesRequest $request, MeasurementTypeAdministration $records)
    {
        return MeasurementTypeResource::collection($records->search($request->validated()));
    }

    public function store(SaveMeasurementTypeRequest $request, MeasurementTypeAdministration $records)
    {
        return (new MeasurementTypeResource($records->save($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function update(SaveMeasurementTypeRequest $request, MeasurementType $measurementType, MeasurementTypeAdministration $records)
    {
        return new MeasurementTypeResource($records->save($request->user(), $request->validated(), $measurementType));
    }
}
