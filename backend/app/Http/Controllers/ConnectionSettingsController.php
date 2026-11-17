<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveConnectionSettingsRequest;
use App\Services\ConnectionCheck;
use App\Services\ConnectionConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConnectionSettingsController extends Controller
{
    public function show(string $group, ConnectionConfiguration $settings): array
    {
        Gate::authorize('instance-settings.manage');

        return ['data' => $settings->payload($group)];
    }

    public function check(string $group, Request $request, ConnectionCheck $check): array
    {
        Gate::authorize('instance-settings.manage');
        $locale = in_array($request->input('locale'), ['en', 'fr'], true) ? $request->input('locale') : 'en';

        return ['data' => ['message' => $check->run($group, $request->user(), $request->session()->getId(), $locale)]];
    }

    public function update(string $group, SaveConnectionSettingsRequest $request, ConnectionConfiguration $settings): array
    {
        $settings->save($request->user(), $group, $request->validated());

        return ['data' => $settings->payload($group)];
    }
}
