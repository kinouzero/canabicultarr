<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListPlantsRequest;
use App\Http\Requests\RecordPlantActionRequest;
use App\Http\Requests\RecordPlantMaintenanceRequest;
use App\Http\Requests\RecordPlantWateringRequest;
use App\Http\Requests\SavePlantRequest;
use App\Http\Resources\PlantEventResource;
use App\Http\Resources\PlantResource;
use App\Models\Plant;
use App\Rules\ZonedTimestamp;
use App\Services\PlantInventory;
use App\Services\PlantMeasurements;
use App\Services\PlantTracking;
use App\Services\PlantWateringNeeds;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlantController extends Controller
{
    public function index(ListPlantsRequest $request, PlantTracking $tracking)
    {
        $records = $tracking->search($request->validated());
        app(PlantWateringNeeds::class)->prepare($records->getCollection());
        app(PlantMeasurements::class)->prepare($records->getCollection());

        return PlantResource::collection($records);
    }

    public function show(Plant $plant, PlantTracking $tracking)
    {
        Gate::authorize('view', $plant);

        return new PlantResource($plant->load($tracking->relations()));
    }

    public function store(SavePlantRequest $request, PlantTracking $tracking)
    {
        return (new PlantResource($tracking->create($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Plant $plant, PlantTracking $service)
    {
        Gate::authorize('delete', $plant);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->delete($request->user(), $plant, $data['version']);

        return response()->noContent();
    }

    public function update(SavePlantRequest $request, Plant $plant, PlantTracking $tracking)
    {
        return new PlantResource($tracking->update($request->user(), $plant, $request->validated()));
    }

    public function action(RecordPlantActionRequest $request, Plant $plant, PlantTracking $tracking)
    {
        return new PlantResource($tracking->action($request->user(), $plant, $request->validated()));
    }

    public function consumables(Request $request, Plant $plant, PlantInventory $inventory)
    {
        Gate::authorize('update', $plant);

        $input = $request->validate(['action' => ['sometimes', 'required', 'string', 'max:32'], 'at' => ['sometimes', 'required', new ZonedTimestamp('plants.validation.invalid')]]);

        return ['data' => $inventory->options($request->user(), $plant, $input['action'] ?? null, isset($input['at']) ? CarbonImmutable::parse($input['at'])->utc() : null)];
    }

    public function water(RecordPlantWateringRequest $request, Plant $plant, PlantTracking $tracking)
    {
        return new PlantResource($tracking->water($request->user(), $plant, $request->validated()));
    }

    public function maintenanceTypes(PlantTracking $tracking)
    {
        return response()->json(['data' => $tracking->maintenanceTypes()]);
    }

    public function maintain(RecordPlantMaintenanceRequest $request, Plant $plant, PlantTracking $tracking)
    {
        return new PlantResource($tracking->maintain($request->user(), $plant, $request->validated()));
    }

    public function events(Request $request, Plant $plant)
    {
        Gate::authorize('view', $plant);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'between:1,100000']]);

        return PlantEventResource::collection($plant->events()->with('documents')->orderByDesc('occurred_at')->orderByDesc('recorded_at')->orderByDesc('plant_version')->orderByDesc('id')->paginate(20, ['*'], 'page', $data['page'] ?? 1));
    }
}
