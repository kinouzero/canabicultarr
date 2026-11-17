<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveInstanceSettingsRequest;
use App\Models\InstanceSetting;
use App\Services\InstanceSettings;
use App\Support\ThemePalette;
use Illuminate\Http\Request;

class InstanceSettingsController extends Controller
{
    public function branding(InstanceSettings $service): array
    {
        $settings = $service->get();

        return ['data' => ['oidcProviderName' => InstanceSettings::providerName($settings), 'oidcProviderIcon' => InstanceSettings::providerIcon($settings), 'siteTitle' => $settings->site_title, 'siteSubtitle' => $settings->site_subtitle, 'version' => $settings->version]];
    }

    public function show(Request $request, InstanceSettings $service)
    {
        return $this->payload($request, $service->get());
    }

    public function update(SaveInstanceSettingsRequest $request, InstanceSettings $service)
    {
        return $this->payload($request, $service->save($request->user(), $request->validated()));
    }

    private function payload(Request $request, InstanceSetting $settings): array
    {
        return ['data' => ['oidcProviderName' => InstanceSettings::providerName($settings), 'oidcProviderIcon' => InstanceSettings::providerIcon($settings), 'siteTitle' => $settings->site_title, 'siteSubtitle' => $settings->site_subtitle, 'oidcProviderNameLocked' => InstanceSettings::providerNameLocked(), 'oidcProviderIconLocked' => InstanceSettings::providerIconLocked(), 'dateFormat' => $settings->date_format, 'timeFormat' => $settings->time_format, 'geneticsColors' => $settings->genetics_colors, 'theme' => ThemePalette::resolve($settings->theme), 'version' => $settings->version, 'canManage' => $request->user()->can('instance-settings.manage')]];
    }
}
