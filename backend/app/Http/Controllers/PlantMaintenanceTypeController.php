<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePlantMaintenanceTypeRequest;
use App\Models\Plant;
use App\Models\PlantMaintenanceType;
use App\Services\PlantMaintenanceTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlantMaintenanceTypeController extends Controller
{
    public function index(PlantMaintenanceTypes $types)
    {
        return response()->json(['data' => $types->records()]);
    }

    public function store(SavePlantMaintenanceTypeRequest $request, PlantMaintenanceTypes $types)
    {
        return response()->json(['data' => $types->save($request->user(), $request->validated())], 201);
    }

    public function update(SavePlantMaintenanceTypeRequest $request, PlantMaintenanceType $maintenanceType, PlantMaintenanceTypes $types)
    {
        return response()->json(['data' => $types->save($request->user(), $request->validated(), $maintenanceType)]);
    }

    public function destroy(Request $request, PlantMaintenanceType $maintenanceType, PlantMaintenanceTypes $types)
    {
        Gate::authorize('create', Plant::class);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $types->remove($request->user(), $maintenanceType, $data['version']);

        return response()->noContent();
    }
}
