<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\PumpActivation;
use App\Services\PumpControl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PumpController extends Controller
{
    public function targets(Request $request, PumpControl $pumps)
    {
        Gate::authorize('create', Asset::class);
        $data = $request->validate(['provider' => ['required', Rule::in(['home_assistant', 'homebridge'])], 'asset_id' => ['sometimes', 'required', 'uuid']]);

        $asset = isset($data['asset_id']) ? Asset::findOrFail($data['asset_id']) : null;
        if ($asset) {
            Gate::authorize('update', $asset);
        }

        return ['data' => $pumps->targets($data['provider'], $asset)];
    }

    public function context(Request $request, Asset $asset, PumpControl $pumps)
    {
        $criteria = $request->validate(['search' => ['sometimes', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'min:1'], 'plant_id' => ['sometimes', 'uuid']]);

        return ['data' => $pumps->context($request->user(), $asset, $criteria)];
    }

    public function status(Request $request, Asset $asset, PumpControl $pumps)
    {
        return ['data' => $pumps->status($request->user(), $asset)];
    }

    public function stop(Request $request, Asset $asset, PumpActivation $activation, PumpControl $pumps)
    {
        abort_unless($activation->asset_id === $asset->id, 404);

        return ['data' => $pumps->stop($activation, $request->user())];
    }

    public function activate(Request $request, Asset $asset, PumpControl $pumps)
    {
        $data = $request->validate(['plant_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid']]);

        return ['data' => $pumps->activate($request->user(), $asset, strtolower($data['plant_id']), strtolower($data['request_id']))];
    }
}
