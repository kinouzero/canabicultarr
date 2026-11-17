<?php

namespace App\Services;

use App\Exceptions\AlertException;
use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\Sensor;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MonitoringAlerts
{
    public const SORT_COLUMNS = ['occurred' => 'opened_at', 'severity' => 'severity'];

    public static function relations(): array
    {
        return SensorAdministration::relations('sensor.');
    }

    public function rules(array $criteria)
    {
        $query = AlertRule::with(self::relations());
        if (! empty($criteria['sensor_id'])) {
            $query->where('sensor_id', $criteria['sensor_id']);
        }
        if (($search = trim($criteria['search'] ?? '')) !== '') {
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%']);
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, ['name' => 'name', 'created' => 'created_at'])->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function episodes(array $criteria)
    {
        $query = AlertEpisode::with('acknowledger:id,uuid,name');
        $state = $criteria['state'] ?? 'active';
        if ($state === 'active') {
            $query->whereNull('resolved_at');
        } elseif ($state === 'resolved') {
            $query->whereNotNull('resolved_at');
        }
        if (isset($criteria['acknowledged'])) {
            if ($criteria['acknowledged']) {
                $query->whereNotNull('acknowledged_at');
            } else {
                $query->whereNull('acknowledged_at');
            }
        }
        if (! empty($criteria['severity'])) {
            $query->where('severity', $criteria['severity']);
        }
        if (! empty($criteria['sensor_id'])) {
            $query->whereHas('rule', fn ($q) => $q->where('sensor_id', $criteria['sensor_id']));
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, [...self::SORT_COLUMNS, 'severity' => DB::raw("CASE severity WHEN 'critical' THEN 3 WHEN 'warning' THEN 2 ELSE 1 END")], 'occurred_desc')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function save(User $actor, array $data, ?AlertRule $target = null): AlertRule
    {
        [$rule,$fields] = DB::transaction(function () use ($actor, $data, $target) {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('create', AlertRule::class);
            $sensor = Sensor::findOrFail($target?->sensor_id ?? $data['sensor_id']);
            $asset = Asset::whereKey($sensor->asset_id)->lockForUpdate()->firstOrFail();
            app(LocationAccess::class)->assertRecord($current, $asset);
            Sensor::whereKey($sensor->id)->lockForUpdate()->firstOrFail();
            $rule = $target ? AlertRule::whereKey($target->id)->lockForUpdate()->firstOrFail() : new AlertRule;
            if ($target && (int) $data['version'] !== $rule->version) {
                throw new AlertException('alerts.errors.conflict');
            }
            if (! $target) {
                $rule->sensor_id = $sensor->id;
                $rule->kind = $data['kind'];
            }
            $rule->fill(Arr::only($data, ['name', 'severity', 'is_enabled']));
            $rule->name = trim($rule->name);
            if ($rule->kind === 'threshold') {
                foreach (['minimum', 'maximum'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $rule->$field = $data[$field] === null ? null : MeasurementValue::normalize($data[$field]);
                    }
                }
                if ($rule->minimum === null && $rule->maximum === null) {
                    throw ValidationException::withMessages(['minimum' => ['alerts.validation.boundsRequired']]);
                }
                if ($rule->minimum !== null && $rule->maximum !== null && bccomp($rule->minimum, $rule->maximum, 9) > 0) {
                    throw ValidationException::withMessages(['maximum' => ['alerts.validation.boundsOrder']]);
                }
                if (array_key_exists('max_age_seconds', $data)) {
                    $rule->max_age_seconds = $data['max_age_seconds'];
                }
            } elseif (array_key_exists('delay_seconds', $data)) {
                $rule->delay_seconds = $data['delay_seconds'];
            }
            $fields = array_values(array_intersect(array_keys($rule->getDirty()), ['name', 'severity', 'minimum', 'maximum', 'max_age_seconds', 'delay_seconds', 'is_enabled', 'sensor_id', 'kind']));
            if (! $target || $fields) {
                if ($target) {
                    $this->resolve($rule, $rule->is_enabled ? 'rule_changed' : 'rule_disabled');
                }
                $rule->version = $target ? $rule->version + 1 : 1;
                $rule->activated_at = now();
                $rule->last_evaluated_at = null;
                $rule->last_result = $rule->is_enabled ? 'waiting' : 'disabled';
                $rule->save();
            }

            return [$rule, $fields];
        });
        if ($fields) {
            Log::notice($target ? 'alert.rule_updated' : 'alert.rule_created', ['actor_uuid' => $actor->uuid, 'rule_uuid' => $rule->id, 'fields' => $fields]);
        }

        return $rule->load(self::relations());
    }

    public function resolve(AlertRule $rule, string $reason, ?array $observation = null): void
    {
        $episode = $rule->episodes()->whereNull('resolved_at')->lockForUpdate()->first();
        if ($episode) {
            $episode->forceFill(['resolved_at' => now(), 'resolution' => $reason, 'resolution_observation' => $observation])->save();
            if ($reason === 'recovered') {
                app(NotificationOutbox::class)->enqueue($episode, 'recovered');
            }
            Log::info('alert.resolved', ['rule_uuid' => $rule->id, 'episode_uuid' => $episode->id, 'reason' => $reason]);
        }
    }

    public function acknowledge(User $actor, AlertEpisode $target): AlertEpisode
    {
        return DB::transaction(function () use ($actor, $target) {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('acknowledge', $target);
            AlertRule::whereKey($target->alert_rule_id)->lockForUpdate()->firstOrFail();
            $episode = AlertEpisode::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($episode->acknowledged_at === null) {
                $episode->acknowledged_at = now();
                $episode->acknowledged_by = $current->id;
                $episode->acknowledger_uuid = $current->uuid;
                $episode->save();
                Log::notice('alert.acknowledged', ['actor_uuid' => $current->uuid, 'episode_uuid' => $episode->id]);
            }

            return $episode->load('acknowledger:id,uuid,name');
        });
    }
}
