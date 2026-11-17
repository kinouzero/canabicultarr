<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveLifecycleSettingsRequest;
use App\Services\LifecycleDefinition;
use App\Services\StrainCatalog;
use Illuminate\Http\Request;

class LifecycleSettingsController extends Controller
{
    public function show(Request $request, StrainCatalog $catalog)
    {
        return $this->payload($request, $catalog->settings());
    }

    public function update(SaveLifecycleSettingsRequest $request, StrainCatalog $catalog)
    {
        return $this->payload($request, $catalog->saveSettings($request->user(), $request->validated()));
    }

    private function payload(Request $request, $settings): array
    {
        return ['data' => ['automaticRelocation' => $settings->automatic_relocation, 'stages' => $settings->stages, 'icons' => LifecycleDefinition::ICONS, 'wateringDefaults' => ['alertDays' => $settings->watering_alert_days, 'moistureThreshold' => $settings->watering_moisture_threshold], 'durations' => $settings->durations, 'version' => $settings->version, 'canManage' => $request->user()->can('lifecycle.manage')]];
    }
}
