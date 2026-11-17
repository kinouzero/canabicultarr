<?php

namespace App\Http\Controllers;

use App\Http\Requests\HomebridgeRequest;
use App\Http\Resources\RemoteSensorLinkResource;
use App\Models\HomebridgeLink;
use App\Services\CollectionPagination;
use App\Services\HomebridgeClient;
use App\Services\IntegrationChannelFilters;
use App\Services\LocationAccess;
use App\Services\RemoteSensorCollection;
use App\Services\RemoteSensorImport;
use App\Services\RemoteSyncSchedule;
use App\Services\SensorAdministration;

class HomebridgeController extends Controller
{
    public function status(HomebridgeRequest $request, HomebridgeClient $client)
    {
        return ['data' => ['configured' => $client->configured(), 'sync' => app(RemoteSyncSchedule::class)->status($client)]];
    }

    public function check(HomebridgeRequest $request, HomebridgeClient $client)
    {
        app(LocationAccess::class)->assertGlobal();
        $client->check();

        return ['data' => ['connected' => true]];
    }

    public function entities(HomebridgeRequest $request, HomebridgeClient $client)
    {
        app(LocationAccess::class)->assertGlobal();
        $result = (new RemoteSensorImport($client))->discover($request->validated());

        return ['data' => $result->items(), 'meta' => ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'per_page' => $result->perPage(), 'total' => $result->total()]];
    }

    public function import(HomebridgeRequest $request, HomebridgeClient $client)
    {
        app(LocationAccess::class)->assertGlobal();

        return response()->json(['data' => (new RemoteSensorImport($client))->importDevices($request->user(), $request->validated('device_ids'))], 201);
    }

    public function links(HomebridgeRequest $request)
    {
        $criteria = $request->validated();
        $query = HomebridgeLink::with(SensorAdministration::relations('sensor.'));

        return RemoteSensorLinkResource::collection(IntegrationChannelFilters::apply($query, $criteria, 'entity_id')->orderBy('entity_id')->paginate(CollectionPagination::size($criteria)));
    }

    public function update(HomebridgeRequest $request, HomebridgeLink $link, HomebridgeClient $client)
    {
        return new RemoteSensorLinkResource((new RemoteSensorImport($client))->toggle($request->user(), $link, $request->validated()));
    }

    public function destroy(HomebridgeRequest $request, HomebridgeLink $link, HomebridgeClient $client)
    {
        (new RemoteSensorImport($client))->remove($request->user(), $link, $request->validated('version'));

        return response()->noContent();
    }

    public function collect(HomebridgeRequest $request, HomebridgeLink $link, HomebridgeClient $client, RemoteSensorCollection $collection)
    {
        $result = $collection->enqueue($client, $link, $request->user());

        return response()->json(['data' => $result], $result['outcome'] === 'queued' ? 202 : 200);
    }
}
