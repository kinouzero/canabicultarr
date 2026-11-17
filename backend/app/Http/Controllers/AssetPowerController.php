<?php

namespace App\Http\Controllers;

use App\Enums\DelegatedPermission;
use App\Models\Asset;
use App\Services\AssetPowerControl;
use App\Services\DeviceControlClient;
use App\Services\LocationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssetPowerController extends Controller
{
    public function targets(Request $request, DeviceControlClient $client)
    {
        Gate::authorize('create', Asset::class);
        abort_unless($request->user()->hasOperationalPermission(DelegatedPermission::HomeAssistant), 403);
        app(LocationAccess::class)->assertGlobal($request->user());
        $data = $request->validate(['provider' => ['required', Rule::in(['home_assistant', 'homebridge'])], 'asset_id' => ['sometimes', 'required', 'uuid']]);

        $asset = isset($data['asset_id']) ? Asset::findOrFail($data['asset_id']) : null;
        if ($asset) {
            Gate::authorize('update', $asset);
        }

        return ['data' => $client->targets($data['provider'], asset: $asset)];
    }

    public function show(Request $request, Asset $asset, AssetPowerControl $power)
    {
        return ['data' => $power->context($request->user(), $asset)];
    }

    public function command(Request $request, Asset $asset, AssetPowerControl $power)
    {
        $data = $request->validate(['is_on' => ['required', 'boolean'], 'request_id' => ['required', 'uuid']]);

        return ['data' => $power->command($request->user(), $asset, (bool) $data['is_on'], strtolower($data['request_id']))];
    }
}
