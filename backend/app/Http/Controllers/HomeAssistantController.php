<?php

namespace App\Http\Controllers;

use App\Http\Requests\HomeAssistantRequest;
use App\Http\Resources\RemoteSensorLinkResource;
use App\Models\HomeAssistantLink;
use App\Services\CollectionPagination;
use App\Services\HomeAssistantClient;
use App\Services\IntegrationChannelFilters;
use App\Services\LocationAccess;
use App\Services\RemoteSensorCollection;
use App\Services\RemoteSensorImport;
use App\Services\RemoteSyncSchedule;
use App\Services\SensorAdministration;

class HomeAssistantController extends Controller
{
    public function status(HomeAssistantRequest $request, HomeAssistantClient $client)
    {
        return ['data' => ['configured' => $client->configured(), 'sync' => app(RemoteSyncSchedule::class)->status($client)]];
    }

    public function check(HomeAssistantRequest $request, HomeAssistantClient $client)
    {
        app(LocationAccess::class)->assertGlobal();
        $client->check();

        return ['data' => ['connected' => true]];
    }

    public function entities(HomeAssistantRequest $request, RemoteSensorImport $import)
    {
        app(LocationAccess::class)->assertGlobal();
        $result = $import->discover($request->validated());

        return ['data' => $result->items(), 'meta' => ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'per_page' => $result->perPage(), 'total' => $result->total()]];
    }

    public function import(HomeAssistantRequest $request, RemoteSensorImport $import)
    {
        app(LocationAccess::class)->assertGlobal();

        return response()->json(['data' => $import->importDevices($request->user(), $request->validated('device_ids'))], 201);
    }

    public function links(HomeAssistantRequest $request)
    {
        $criteria = $request->validated();
        $query = HomeAssistantLink::with(SensorAdministration::relations('sensor.'));

        return RemoteSensorLinkResource::collection(IntegrationChannelFilters::apply($query, $criteria, 'entity_id')->orderBy('entity_id')->paginate(CollectionPagination::size($criteria)));
    }

    public function update(HomeAssistantRequest $request, HomeAssistantLink $link, RemoteSensorImport $import)
    {
        return new RemoteSensorLinkResource($import->toggle($request->user(), $link, $request->validated()));
    }

    public function destroy(HomeAssistantRequest $request, HomeAssistantLink $link, RemoteSensorImport $import)
    {
        $import->remove($request->user(), $link, $request->validated('version'));

        return response()->noContent();
    }

    public function collect(HomeAssistantRequest $request, HomeAssistantLink $link, HomeAssistantClient $client, RemoteSensorCollection $collection)
    {
        $result = $collection->enqueue($client, $link, $request->user());

        return response()->json(['data' => $result], $result['outcome'] === 'queued' ? 202 : 200);
    }
}
