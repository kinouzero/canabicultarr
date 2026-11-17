<?php

namespace App\Http\Controllers;

use App\Http\Requests\HttpSampleRequest;
use App\Http\Requests\HttpSourceRequest;
use App\Http\Resources\HttpSourceResource;
use App\Models\HttpMeasurementSource;
use App\Services\HttpMeasurementIngestion;
use App\Services\HttpSourceAdministration;

class HttpSourceController extends Controller
{
    public function destroy(HttpSourceRequest $request, HttpMeasurementSource $source, HttpSourceAdministration $sources)
    {
        $sources->apply($request->user(), $request->validated(), $source, 'remove');

        return response()->noContent();
    }

    public function index(HttpSourceRequest $request, HttpSourceAdministration $sources)
    {
        return HttpSourceResource::collection($sources->listing($request->user(), $request->validated()));
    }

    public function store(HttpSourceRequest $request, HttpSourceAdministration $sources)
    {
        return $this->change($request, $sources);
    }

    public function update(HttpSourceRequest $request, HttpMeasurementSource $source, HttpSourceAdministration $sources)
    {
        return $this->change($request, $sources, $source, 'toggle');
    }

    public function rotate(HttpSourceRequest $request, HttpMeasurementSource $source, HttpSourceAdministration $sources)
    {
        return $this->change($request, $sources, $source, 'rotate');
    }

    public function revoke(HttpSourceRequest $request, HttpMeasurementSource $source, HttpSourceAdministration $sources)
    {
        return $this->change($request, $sources, $source, 'revoke');
    }

    private function change(HttpSourceRequest $request, HttpSourceAdministration $sources, ?HttpMeasurementSource $source = null, string $action = 'create')
    {
        [$record, $token] = $sources->apply($request->user(), $request->validated(), $source, $action);

        return response()->json(['data' => ['source' => new HttpSourceResource($record), 'token' => $token]], $source ? 200 : 201);
    }

    public function ingest(HttpSampleRequest $request, HttpMeasurementIngestion $ingestion)
    {
        $outcome = $ingestion->ingest($request->attributes->get('http_source'), $request->bearerToken(), $request->validated());

        return response()->json(['data' => ['outcome' => $outcome]], $outcome === 'stored' ? 201 : 200);
    }
}
