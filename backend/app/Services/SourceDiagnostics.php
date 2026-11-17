<?php

namespace App\Services;

use App\Enums\MeasurementQuality;
use App\Models\Asset;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SourceDiagnostics
{
    public const SORT_COLUMNS = ['name' => 'sensor_sort', 'observed' => 'last_observed_at', 'accepted' => 'last_accepted_at'];

    private const STATES = ['enabled', 'paused', 'sensor_disabled', 'device_retired', 'credential_invalid', 'source_unavailable'];

    private const FRESHNESS = ['fresh', 'stale', 'missing', 'unknown'];

    private const ERRORS = ['notConfigured', 'accessDenied', 'entityMissing', 'providerUnavailable', 'responseTooLarge', 'invalidResponse', 'typeConflict', 'conflict', 'disabled', 'instanceChanged', 'invalidValue', 'unitMismatch', 'invalidTime', 'sampleConflict', 'deviceRetired'];

    private function authorize(User $actor): void
    {
        $current = User::find($actor->id);
        if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }
        Gate::forUser($current)->authorize('integrations.manage');
    }

    private function sources(CarbonImmutable $asOf)
    {
        $latest = DB::table('measurements as m')->whereIn('m.quality', MeasurementQuality::USABLE)->where('m.observed_at', '<=', $asOf->format('Y-m-d H:i:s.u'))->whereColumn('m.sensor_id', 's.id')->orderByDesc('m.observed_at')->limit(1);
        app(LocationAccess::class)->measurement($latest, 'm');
        $common = ['x.id', 's.id as sensor_id', 's.name as sensor_name', 's.name_key as sensor_sort', 'a.name as device_name', 's.is_enabled as sensor_enabled', 's.expected_interval_seconds', 'a.status as device_status', 'x.is_enabled'];
        $nullTimestamp = DB::getDriverName() === 'pgsql' ? 'CAST(NULL AS timestamptz)' : 'NULL';
        $ha = DB::table('home_assistant_links as x')->whereNull('x.deleted_at')->join('sensors as s', 's.id', '=', 'x.sensor_id')->join('assets as a', 'a.id', '=', 's.asset_id')
            ->select($common)->selectRaw("'home_assistant' AS kind, x.last_attempt_at, x.last_success_at, {$nullTimestamp} AS last_accepted_at, CAST(NULL AS INTEGER) AS credential_valid, x.last_error")
            ->selectSub((clone $latest)->select('m.observed_at'), 'last_observed_at')->selectSub((clone $latest)->select('m.received_at'), 'latest_received_at');
        $homebridge = DB::table('homebridge_links as x')->whereNull('x.deleted_at')->join('sensors as s', 's.id', '=', 'x.sensor_id')->join('assets as a', 'a.id', '=', 's.asset_id')
            ->select($common)->selectRaw("'homebridge' AS kind, x.last_attempt_at, x.last_success_at, {$nullTimestamp} AS last_accepted_at, CAST(NULL AS INTEGER) AS credential_valid, x.last_error")
            ->selectSub((clone $latest)->select('m.observed_at'), 'last_observed_at')->selectSub((clone $latest)->select('m.received_at'), 'latest_received_at');
        $issuer = User::whereColumn('users.id', 'x.issuer_id')->where('users.is_active', true)->whereColumn('users.session_version', 'x.issuer_version')
            ->withPermission('integrations.manage');
        $geoChecks = [];
        foreach (['site', 'room', 'zone'] as $type) {
            $typeJson = DB::getDriverName() === 'pgsql' ? "g.value->>'type'" : "json_extract(g.value, '$.type')";
            $idJson = DB::getDriverName() === 'pgsql' ? "g.value->>'id'" : "json_extract(g.value, '$.id')";
            $geoChecks[] = '('.$typeJson." = '".$type."' AND ".$idJson.' = CAST(ab.'.$type.'_id AS TEXT))';
        }
        $entries = DB::getDriverName() === 'pgsql' ? 'jsonb_array_elements(users.location_access::jsonb)' : 'json_each(users.location_access)';
        $issuer->where(fn ($q) => $q->whereNull('users.location_access')->orWhereRaw('EXISTS (SELECT 1 FROM asset_boundaries ab WHERE ab.asset_id = a.id) AND NOT EXISTS (SELECT 1 FROM asset_boundaries ab WHERE ab.asset_id = a.id AND NOT EXISTS (SELECT 1 FROM '.$entries.' AS g WHERE '.implode(' OR ', $geoChecks).'))'));
        $http = DB::table('http_measurement_sources as x')->join('sensors as s', 's.id', '=', 'x.sensor_id')->join('assets as a', 'a.id', '=', 's.asset_id')
            ->select($common)->selectRaw("'http' AS kind, NULL AS last_attempt_at, NULL AS last_success_at, x.last_received_at AS last_accepted_at")
            ->selectRaw('CASE WHEN x.token_hash IS NOT NULL AND x.expires_at > ? AND EXISTS ('.$issuer->toSql().') THEN 1 ELSE 0 END AS credential_valid', [$asOf->format('Y-m-d H:i:s.u'), ...$issuer->getBindings()])
            ->selectRaw('NULL AS last_error')->selectSub((clone $latest)->select('m.observed_at'), 'last_observed_at')->selectSub((clone $latest)->select('m.received_at'), 'latest_received_at');
        $mqtt = DB::table('mqtt_measurement_sources as x')->join('sensors as s', 's.id', '=', 'x.sensor_id')->join('assets as a', 'a.id', '=', 's.asset_id')
            ->select($common)->selectRaw("'mqtt' AS kind, x.last_attempt_at, NULL AS last_success_at, x.last_received_at AS last_accepted_at")
            ->selectRaw('CASE WHEN x.instance_key = ? AND ? = 1 THEN 1 ELSE 0 END AS credential_valid', [app(MqttConfiguration::class)->instanceKey(), app(MqttConfiguration::class)->ready() ? 1 : 0])
            ->selectRaw('x.last_error')->selectSub((clone $latest)->select('m.observed_at'), 'last_observed_at')->selectSub((clone $latest)->select('m.received_at'), 'latest_received_at');
        if (app(LocationAccess::class)->restricted()) {
            foreach ([$ha, $homebridge, $http, $mqtt] as $sourceQuery) {
                $sourceQuery->whereIn('a.id', Asset::select('assets.id'));
            }
        }
        $state = DB::query()->fromSub($ha->unionAll($homebridge)->unionAll($http)->unionAll($mqtt), 'raw_sources')->select('raw_sources.*')->selectRaw("CASE WHEN NOT is_enabled THEN 'paused' WHEN NOT sensor_enabled THEN 'sensor_disabled' WHEN device_status = 'retired' THEN 'device_retired' WHEN kind = 'http' AND credential_valid = 0 THEN 'credential_invalid' WHEN kind = 'mqtt' AND credential_valid = 0 THEN 'source_unavailable' ELSE 'enabled' END AS state");
        $seconds = '(CASE WHEN expected_interval_seconds * 2 > 120 THEN expected_interval_seconds * 2 ELSE 120 END)';
        $cutoff = DB::getDriverName() === 'pgsql' ? 'CAST(? AS timestamptz) - '.$seconds." * INTERVAL '1 second'" : "datetime(?, '-' || ".$seconds." || ' seconds')";

        return DB::query()->fromSub($state, 'configured_sources')->select('configured_sources.*')
            ->selectRaw("CASE WHEN last_observed_at IS NULL THEN 'missing' WHEN expected_interval_seconds IS NULL THEN 'unknown' WHEN last_observed_at < ".$cutoff." THEN 'stale' ELSE 'fresh' END AS freshness", [$asOf->format('Y-m-d H:i:s')]);
    }

    private function time(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->toISOString();
    }

    public function read(User $actor, array $criteria): array
    {
        return app(LocationAccess::class)->run($actor, fn () => $this->build($actor, $criteria));
    }

    private function build(User $actor, array $criteria): array
    {
        $this->authorize($actor);
        $asOf = CarbonImmutable::now()->utc()->startOfSecond();
        $base = $this->sources($asOf);
        $aggregates = ['COUNT(*) AS total', "SUM(CASE WHEN kind = 'home_assistant' THEN 1 ELSE 0 END) AS home_assistant", "SUM(CASE WHEN kind = 'homebridge' THEN 1 ELSE 0 END) AS homebridge", "SUM(CASE WHEN kind = 'http' THEN 1 ELSE 0 END) AS http", "SUM(CASE WHEN kind = 'mqtt' THEN 1 ELSE 0 END) AS mqtt"];
        foreach (self::STATES as $state) {
            $aggregates[] = "SUM(CASE WHEN state = '".$state."' THEN 1 ELSE 0 END) AS ".$state;
        }
        foreach (self::FRESHNESS as $freshness) {
            $aggregates[] = "SUM(CASE WHEN state = 'enabled' AND freshness = '".$freshness."' THEN 1 ELSE 0 END) AS readings_".$freshness;
        }
        $counts = DB::query()->fromSub(clone $base, 'source_status')->selectRaw(implode(', ', $aggregates))->first();
        $filtered = DB::query()->fromSub($base, 'sources');
        foreach (['kind', 'state', 'freshness'] as $filter) {
            if (filled($criteria[$filter] ?? null)) {
                $filtered->where($filter, $criteria[$filter]);
            }
        }
        if (filled($criteria['search'] ?? null)) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($criteria['search']))).'%';
            $filtered->where(fn ($q) => $q->whereRaw("sensor_sort LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("LOWER(device_name) LIKE ? ESCAPE '!'", [$pattern]));
        }
        $records = CollectionSorting::apply($filtered, $criteria['sort'] ?? null, self::SORT_COLUMNS)->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
        $rows = [];
        foreach ($records as $record) {
            $mqttError = $record->kind === 'mqtt' && in_array($record->last_error, ['mqtt.errors.invalidSample', 'mqtt.errors.sampleConflict', 'mqtt.errors.failed'], true);
            $known = $mqttError || is_string($record->last_error) && str_starts_with($record->last_error, 'measurements.errors.') && in_array(substr($record->last_error, strlen('measurements.errors.')), self::ERRORS, true);
            $rows[] = ['id' => $record->id, 'kind' => $record->kind, 'sensorId' => $record->sensor_id, 'sensorName' => $record->sensor_name, 'deviceName' => $record->device_name,
                'state' => $record->state, 'freshness' => $record->freshness, 'credentialCurrent' => $record->kind === 'http' ? (bool) $record->credential_valid : null,
                'lastObservedAt' => $this->time($record->last_observed_at), 'latestReceivedAt' => $this->time($record->latest_received_at), 'lastAttemptAt' => $this->time($record->last_attempt_at), 'lastSuccessAt' => $this->time($record->last_success_at), 'lastAcceptedAt' => $this->time($record->last_accepted_at),
                'lastError' => $known ? $record->last_error : null, 'hasUnknownError' => filled($record->last_error) && ! $known];
        }
        $summary = ['total' => (int) $counts->total, 'homeAssistant' => (int) $counts->home_assistant, 'homebridge' => (int) $counts->homebridge, 'http' => (int) $counts->http, 'mqtt' => (int) $counts->mqtt, 'states' => [], 'readings' => []];
        foreach (self::STATES as $state) {
            $summary['states'][$state] = (int) $counts->{$state};
        }
        foreach (self::FRESHNESS as $freshness) {
            $summary['readings'][$freshness] = (int) $counts->{'readings_'.$freshness};
        }
        $this->authorize($actor);

        return ['data' => $rows, 'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $records->perPage(), 'total' => $records->total()], 'summary' => $summary, 'asOf' => $asOf->toISOString(), 'generatedAt' => CarbonImmutable::now()->utc()->toISOString()];
    }
}
