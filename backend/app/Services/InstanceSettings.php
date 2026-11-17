<?php

namespace App\Services;

use App\Exceptions\InstanceSettingsException;
use App\Models\InstanceSetting;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class InstanceSettings
{
    public static function providerNameLocked(): bool
    {
        return is_string(config('oidc.provider_name')) && trim(config('oidc.provider_name')) !== '';
    }

    public static function providerName(InstanceSetting $settings): string
    {
        return self::providerNameLocked() ? trim(config('oidc.provider_name')) : $settings->oidc_provider_name;
    }

    public static function providerIconLocked(): bool
    {
        return is_string(config('oidc.provider_icon')) && trim(config('oidc.provider_icon')) !== '';
    }

    public static function providerIcon(InstanceSetting $settings): ?string
    {
        $icon = self::providerIconLocked() ? trim(config('oidc.provider_icon')) : $settings->oidc_provider_icon;

        return is_string($icon) && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $icon) ? $icon : null;
    }

    public function get(): InstanceSetting
    {
        return InstanceSetting::findOrFail(1);
    }

    public function save(User $actor, array $data): InstanceSetting
    {
        [$settings, $changed] = DB::transaction(function () use ($actor, $data) {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($current->session_version !== $actor->session_version) {
                throw new AuthenticationException;
            }
            Gate::forUser($current)->authorize('instance-settings.manage');
            $settings = InstanceSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($settings->version !== (int) $data['version']) {
                throw new InstanceSettingsException('instanceSettings.conflict');
            }
            $settings->fill(['date_format' => $data['date_format'], 'time_format' => $data['time_format']]);
            foreach (['site_title', 'site_subtitle', 'oidc_provider_name', 'oidc_provider_icon'] as $field) {
                if (array_key_exists($field, $data)) {
                    $settings->{$field} = $data[$field];
                }
            }
            if (isset($data['genetics_colors'])) {
                $colors = [];
                foreach (['sativa', 'indica', 'hybrid'] as $kind) {
                    $colors[$kind] = strtolower($data['genetics_colors'][$kind]);
                }
                $settings->genetics_colors = $colors;
            }
            if (isset($data['theme'])) {
                $theme = $data['theme'];
                foreach (['light', 'dark'] as $mode) {
                    $theme[$mode] = array_map(strtolower(...), $theme[$mode]);
                }
                $settings->theme = $theme;
            }
            $changed = $settings->isDirty();
            if ($changed) {
                $settings->version++;
                $settings->save();
            }

            return [$settings, $changed];
        });
        Log::notice('instance_settings.updated', ['actor_uuid' => $actor->uuid, 'changed' => $changed]);

        return $settings;
    }
}
