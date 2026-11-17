<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListStrainsRequest;
use App\Http\Requests\SaveStrainRequest;
use App\Http\Resources\StrainResource;
use App\Models\Strain;
use App\Services\StrainCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StrainController extends Controller
{
    public function index(ListStrainsRequest $request, StrainCatalog $catalog)
    {
        return StrainResource::collection($catalog->search($request->validated()));
    }

    public function show(Strain $strain)
    {
        Gate::authorize('view', $strain);

        return new StrainResource($strain);
    }

    public function store(SaveStrainRequest $request, StrainCatalog $catalog)
    {
        return (new StrainResource($catalog->save($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Strain $strain, StrainCatalog $service)
    {
        Gate::authorize('delete', $strain);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->delete($request->user(), $strain, $data['version']);

        return response()->noContent();
    }

    public function update(SaveStrainRequest $request, Strain $strain, StrainCatalog $catalog)
    {
        return new StrainResource($catalog->save($request->user(), $request->validated(), $strain));
    }
}
