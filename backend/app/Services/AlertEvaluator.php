<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\AssetContext;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\HttpMeasurementSource;
use App\Models\Measurement;
use App\Models\MqttMeasurementSource;
use App\Models\Sensor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class AlertEvaluator
{
    public function __construct(private MonitoringAlerts $alerts) {}

    public function evaluate(AlertRule $target, ?User $actor = null): AlertRule
    {
        return DB::transaction(function () use ($target, $actor) {
            if ($actor) {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize('update', $target);
            }
            $sensor = Sensor::findOrFail($target->sensor_id);
            $asset = Asset::whereKey($sensor->asset_id)->lockForUpdate()->firstOrFail();
            $sensor = Sensor::with('measurementType')->whereKey($sensor->id)->lockForUpdate()->firstOrFail();
            $link = HomeAssistantLink::where('sensor_id', $sensor->id)->lockForUpdate()->first();
            $homebridge = HomebridgeLink::where('sensor_id', $sensor->id)->lockForUpdate()->first();
            $http = HttpMeasurementSource::where('sensor_id', $sensor->id)->lockForUpdate()->first();
            $mqtt = MqttMeasurementSource::where('sensor_id', $sensor->id)->lockForUpdate()->first();
            $rule = AlertRule::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $rule->last_evaluated_at = now();
            if (! $rule->is_enabled) {
                $this->alerts->resolve($rule, 'rule_disabled');
                $rule->last_result = 'disabled';
                $rule->save();

                return $rule->load(MonitoringAlerts::relations());
            }
            if (! $sensor->is_enabled || $asset->status === AssetStatus::Retired || ($link && ! $link->is_enabled) || ($homebridge && ! $homebridge->is_enabled) || ($http && ! $http->is_enabled) || ($mqtt && ! $mqtt->is_enabled)) {
                $this->alerts->resolve($rule, 'source_disabled');
                $rule->last_result = 'suppressed';
                $rule->save();

                return $rule->load(MonitoringAlerts::relations());
            }
            $latest = Measurement::usable()->where('observed_at', '<=', now()->format('Y-m-d H:i:s.u'))->where('sensor_id', $sensor->id)->orderByDesc('observed_at')->first();
            $currentContext = AssetContext::where('asset_id', $asset->id)->orderByDesc('effective_at')->orderByDesc('id')->value('id');
            if ($latest?->provenance_snapshot && $latest->asset_context_id !== $currentContext) {
                $latest = null;
            }
            $open = $rule->episodes()->whereNull('resolved_at')->first();
            if ($open && (isset($open->rule_snapshot['provenanceStatus'])
                ? ($open->rule_snapshot['assetContextId'] ?? null) !== $currentContext
                : $latest?->provenance_snapshot !== null)) {
                $this->alerts->resolve($rule, 'assignment_changed');
            }
            $observation = $latest ? ['id' => $latest->id, 'value' => $latest->value, 'unit' => $latest->unit, 'observedAt' => $latest->observed_at->toISOString()] : null;
            $reason = null;
            if ($rule->kind === 'threshold') {
                if (! $latest || $latest->observed_at->lessThan(now()->subSeconds($rule->max_age_seconds))) {
                    $rule->last_result = 'waiting';
                    $rule->save();

                    return $rule->load(MonitoringAlerts::relations());
                }
                if ($rule->minimum !== null && bccomp($latest->value, $rule->minimum, 9) < 0) {
                    $reason = 'below_minimum';
                } elseif ($rule->maximum !== null && bccomp($latest->value, $rule->maximum, 9) > 0) {
                    $reason = 'above_maximum';
                }
            } else {
                $anchor = $latest && $latest->observed_at->greaterThan($rule->activated_at) ? $latest->observed_at : $rule->activated_at;
                if ($anchor->lessThanOrEqualTo(now()->subSeconds($rule->delay_seconds))) {
                    $reason = $latest ? 'overdue_reading' : 'missing_reading';
                }
            }
            if ($reason === null) {
                $this->alerts->resolve($rule, 'recovered', $observation);
                $rule->last_result = 'normal';
            } else {
                $episode = $rule->episodes()->whereNull('resolved_at')->lockForUpdate()->first();
                if (! $episode) {
                    $snapshot = [
                        ...($latest?->provenance_snapshot || ! $latest ? ['provenanceStatus' => $latest?->provenance_snapshot['status'] ?? 'recorded', 'assetContextId' => $latest?->asset_context_id ?? $currentContext] : []),
                        'location' => $latest ? $latest->location_snapshot : $asset->locationDetails(), 'id' => $rule->id, 'version' => $rule->version, 'name' => $rule->name, 'kind' => $rule->kind, 'severity' => $rule->severity, 'minimum' => $rule->minimum, 'maximum' => $rule->maximum, 'delaySeconds' => $rule->delay_seconds, 'maxAgeSeconds' => $rule->max_age_seconds, 'sensor' => ['id' => $sensor->id, 'name' => $sensor->name, 'deviceName' => $asset->name, 'unit' => $sensor->measurementType->unit]];
                    $episode = $rule->episodes()->create(['rule_snapshot' => $snapshot, 'severity' => $rule->severity, 'reason' => $reason, 'first_observation' => $observation, 'last_observation' => $observation, 'opened_at' => now(), 'last_detected_at' => now()]);
                    app(NotificationOutbox::class)->enqueue($episode, 'opened');
                    Log::notice('alert.opened', ['rule_uuid' => $rule->id, 'episode_uuid' => $episode->id, 'sensor_uuid' => $sensor->id, 'reason' => $reason]);
                } else {
                    $episode->last_detected_at = now();
                    $episode->last_observation = $observation;
                    $episode->save();
                }
                $rule->last_result = 'alerting';
            }
            $rule->save();

            return $rule->load(MonitoringAlerts::relations());
        });
    }
}
