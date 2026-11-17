<?php

namespace App\Services;

use App\Exceptions\ReportException;
use App\Models\AlertEpisode;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class SensorReporting
{
    private ?User $reader = null;

    private ?string $readConnection = null;

    public const MAX_BACKGROUND_EXPORT_ROWS = 100000;

    public const MAX_REPORT_ROWS = 100000;

    public const MAX_EXPORT_ROWS = 10000;

    private function authorize(User $actor, Sensor $sensor): void
    {
        $current = User::find($actor->id);
        if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }
        Gate::forUser($current)->authorize('view', $sensor);
        $this->reader = $current;
    }

    private function snapshot(Closure $work): mixed
    {
        $connection = DB::connection($this->readConnection);
        if ($connection->transactionLevel() !== 0) {
            throw new ReportException('reports.errors.unavailable', 503);
        }

        return $connection->transaction(function () use ($work, $connection) {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $work();
        });
    }

    private function window(array $criteria): array
    {
        $now = CarbonImmutable::now()->utc();
        $asOf = empty($criteria['as_of']) ? $now : CarbonImmutable::parse($criteria['as_of'])->utc();
        $to = empty($criteria['date_to']) ? $asOf : CarbonImmutable::parse($criteria['date_to'])->utc();
        $from = empty($criteria['date_from']) ? $to->subDay() : CarbonImmutable::parse($criteria['date_from'])->utc();
        if ($asOf->greaterThan($now) || $from->greaterThan($to) || $to->greaterThan($asOf) || $from->diffInMicroseconds($to) > 31 * 86400 * 1000000) {
            throw new ReportException('reports.validation.rangeInvalid');
        }

        return [$from, $to, $asOf, $criteria['group_by'] ?? 'hour'];
    }

    private function query(Sensor $sensor, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $asOf)
    {
        $query = Measurement::on($this->readConnection)->usable()->where('sensor_id', $sensor->id)->whereBetween('observed_at', [$from->format('Y-m-d H:i:s.u'), $to->format('Y-m-d H:i:s.u')])->where('received_at', '<=', $asOf->format('Y-m-d H:i:s.u'))->orderBy('observed_at')->orderBy('id');
        app(LocationAccess::class)->measurement($query, 'measurements', $this->reader);

        return $query;
    }

    private function readings(Sensor $sensor, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $asOf, int $limit, bool $locations = false, int $batchSize = 1000): \Generator
    {
        $after = null;
        $read = 0;
        $columns = ['id', 'observed_at', 'received_at', 'value', 'unit', 'quality'];
        if ($locations) {
            $columns = [...$columns, 'location_snapshot', 'asset_context_id', 'provenance_snapshot'];
        }
        while ($read < $limit) {
            $query = $this->query($sensor, $from, $to, $asOf)->select($columns)->limit(min($batchSize, $limit - $read));
            if ($after !== null) {
                $query->where('observed_at', '>', $after);
            }
            $rows = $query->get();
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                $after = $row->observed_at->format('Y-m-d H:i:s.u');
                $read++;
                yield $row;
            }
        }
    }

    private function emptyStats(): array
    {
        return ['count' => 0, 'minimum' => null, 'maximum' => null, 'sum' => '0.000000000'];
    }

    private function add(array &$stats, string $value): void
    {
        $stats['count']++;
        $stats['sum'] = bcadd($stats['sum'], $value, 9);
        if ($stats['minimum'] === null || bccomp($value, $stats['minimum'], 9) < 0) {
            $stats['minimum'] = $value;
        }
        if ($stats['maximum'] === null || bccomp($value, $stats['maximum'], 9) > 0) {
            $stats['maximum'] = $value;
        }
    }

    private function finish(array $stats): array
    {
        $mean = null;
        if ($stats['count']) {
            $raw = bcdiv($stats['sum'], (string) $stats['count'], 10);
            $mean = bcadd($raw, bccomp($raw, '0', 10) < 0 ? '-0.0000000005' : '0.0000000005', 9);
        }
        unset($stats['sum']);

        return [...$stats, 'mean' => $mean];
    }

    private function observation(?Measurement $row): ?array
    {
        return $row ? ['id' => $row->id, 'quality' => $row->quality, 'value' => $row->value, 'unit' => $row->unit, 'observedAt' => $row->observed_at->toISOString(), 'receivedAt' => $row->received_at->toISOString()] : null;
    }

    private function alertSummary(Sensor $sensor, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = AlertEpisode::whereHas('rule', fn ($q) => $q->where('sensor_id', $sensor->id));
        $start = $from->format('Y-m-d H:i:s.u');
        $end = $to->format('Y-m-d H:i:s.u');
        $overlap = (clone $query)->where('opened_at', '<=', $end)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $start));

        return ['overlapping' => $overlap->count(), 'opened' => (clone $query)->whereBetween('opened_at', [$start, $end])->count(), 'resolved' => (clone $query)->whereBetween('resolved_at', [$start, $end])->count(), 'acknowledged' => (clone $query)->whereBetween('acknowledged_at', [$start, $end])->count(), 'activeAtEnd' => (clone $query)->where('opened_at', '<=', $end)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $end))->count()];
    }

    public function report(User $actor, Sensor $sensor, array $criteria): array
    {
        return app(LocationAccess::class)->run($actor, fn () => $this->buildReport($actor, $sensor, $criteria));
    }

    private function buildReport(User $actor, Sensor $sensor, array $criteria): array
    {
        $this->authorize($actor, $sensor);
        [$from,$to,$asOf,$group] = $this->window($criteria);
        $result = $this->snapshot(function () use ($sensor, $from, $to, $asOf, $group) {
            $sensor = Sensor::on($this->readConnection)->with(SensorAdministration::relations())->findOrFail($sensor->id);
            $unit = $sensor->measurementType->unit;
            $stats = $this->emptyStats();
            $buckets = [];
            for ($cursor = $group === 'day' ? $from->startOfDay() : $from->startOfHour(); $cursor->lessThanOrEqualTo($to); $cursor = $group === 'day' ? $cursor->addDay() : $cursor->addHour()) {
                $next = $group === 'day' ? $cursor->addDay() : $cursor->addHour();
                $buckets[$cursor->format('Y-m-d H:i:s')] = ['startAt' => ($cursor->lessThan($from) ? $from : $cursor)->toISOString(), 'endAt' => ($next->greaterThan($to) ? $to : $next)->toISOString(), ...$this->emptyStats()];
            }
            if (DB::getDriverName() === 'pgsql') {
                [$stats, $buckets, $first, $last, $largest, $gapCount, $threshold] = $this->postgresStatistics($sensor, $from, $to, $asOf, $group, $buckets);
            } else {
                if ($this->query($sensor, $from, $to, $asOf)->count() > self::MAX_REPORT_ROWS) {
                    throw new ReportException('reports.errors.tooManyReadings');
                }
                $first = null;
                $last = null;
                $previous = $from;
                $largest = 0;
                $gapCount = $sensor->expected_interval_seconds === null ? null : 0;
                $threshold = $gapCount === null ? null : max(120, 2 * $sensor->expected_interval_seconds) * 1000000;
                $gap = function (CarbonImmutable $start, CarbonImmutable $end) use (&$largest, &$gapCount, $threshold) {
                    $micros = $start->diffInMicroseconds($end);
                    $largest = max($largest, (int) ceil($micros / 1000000));
                    if ($threshold !== null && $micros > $threshold) {
                        $gapCount++;
                    }
                };
                foreach ($this->readings($sensor, $from, $to, $asOf, self::MAX_REPORT_ROWS + 1) as $row) {
                    if ($stats['count'] >= self::MAX_REPORT_ROWS) {
                        throw new ReportException('reports.errors.tooManyReadings');
                    }
                    if ($row->unit !== $unit) {
                        throw new ReportException('reports.errors.unitMismatch', 409);
                    }
                    $this->add($stats, $row->value);
                    $key = ($group === 'day' ? $row->observed_at->startOfDay() : $row->observed_at->startOfHour())->format('Y-m-d H:i:s');
                    $this->add($buckets[$key], $row->value);
                    $gap($previous, $row->observed_at);
                    $previous = $row->observed_at;
                    $first ??= $row;
                    $last = $row;
                }
                $gap($previous, $to);
            }

            return ['sensor' => $sensor, 'window' => ['from' => $from->toISOString(), 'to' => $to->toISOString(), 'asOf' => $asOf->toISOString(), 'groupBy' => $group], 'statistics' => [...$this->finish($stats), 'first' => $this->observation($first), 'last' => $this->observation($last), 'largestGapSeconds' => $largest, 'gapCount' => $gapCount, 'gapThresholdSeconds' => $threshold === null ? null : (int) ($threshold / 1000000)], 'buckets' => array_values(array_map(fn ($b) => $this->finish($b), $buckets)), 'alerts' => $this->alertSummary($sensor, $from, $to), 'exportLimit' => self::MAX_EXPORT_ROWS];
        });
        $this->authorize($actor, $sensor);

        return $result;
    }

    /** Aggregate numeric history in the database; only buckets and edge samples cross the connection. */
    private function postgresStatistics(Sensor $sensor, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $asOf, string $group, array $buckets): array
    {
        $base = $this->query($sensor, $from, $to, $asOf)->reorder();
        $threshold = $sensor->expected_interval_seconds === null ? null : max(120, 2 * $sensor->expected_interval_seconds) * 1000000;
        $timed = (clone $base)->select(['observed_at', 'value', 'unit'])
            ->selectRaw('LAG(observed_at) OVER (ORDER BY observed_at) AS previous_at');
        $gap = 'EXTRACT(EPOCH FROM (observed_at - COALESCE(previous_at, ?::timestamptz))) * 1000000';
        $start = $from->format('Y-m-d H:i:s.uP');
        $summary = DB::query()->fromSub($timed->toBase(), 'readings')
            ->selectRaw('COUNT(*) AS count, MIN(value) AS minimum, MAX(value) AS maximum, SUM(value) AS sum')
            ->selectRaw('COUNT(*) FILTER (WHERE unit IS DISTINCT FROM ?) AS mismatches', [$sensor->measurementType->unit])
            ->selectRaw("COALESCE(MAX($gap), 0) AS largest_gap", [$start])
            ->selectRaw("COUNT(*) FILTER (WHERE $gap > ?) AS gap_count", [$start, $threshold])->first();
        if ($summary->mismatches > 0) {
            throw new ReportException('reports.errors.unitMismatch', 409);
        }
        $stats = fn ($row) => ['count' => (int) $row->count,
            'minimum' => $row->minimum === null ? null : bcadd($row->minimum, '0', 9),
            'maximum' => $row->maximum === null ? null : bcadd($row->maximum, '0', 9), 'sum' => $row->sum ?? '0.000000000'];
        $interval = $group === 'day' ? 'day' : 'hour';
        $bucket = "date_trunc('$interval', observed_at AT TIME ZONE 'UTC')";
        $aggregates = (clone $base)->selectRaw("$bucket AS bucket, COUNT(*) AS count, MIN(value) AS minimum, MAX(value) AS maximum, SUM(value) AS sum")
            ->groupByRaw($bucket)->toBase()->get();
        foreach ($aggregates as $row) {
            $buckets[$row->bucket] = [...$buckets[$row->bucket], ...$stats($row)];
        }
        $first = (clone $base)->orderBy('observed_at')->first();
        $last = (clone $base)->orderByDesc('observed_at')->first();
        $edgeGap = ($last?->observed_at ?? $from)->diffInMicroseconds($to);
        $largest = (int) ceil(max((float) $summary->largest_gap, $edgeGap) / 1000000);
        $gapCount = $threshold === null ? null : (int) $summary->gap_count + (int) ($edgeGap > $threshold);

        return [$stats($summary), $buckets, $first, $last, $largest, $gapCount, $threshold];
    }

    private function textCell(?string $value): string
    {
        $value ??= '';
        $unsafe = preg_match('/^[\p{Z}\p{C}\s]*[=+\-@＝＋－＠]/u', $value) || preg_match('/^[\t\r\n]/', $value);
        $clean = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value) ?? '';

        return ($unsafe ? "'" : '').$clean;
    }

    public function export(User $actor, Sensor $sensor, array $criteria): array
    {
        return app(LocationAccess::class)->run($actor, fn () => $this->buildExport($actor, $sensor, $criteria));
    }

    public function normalizeCriteria(array $criteria): array
    {
        [$from, $to, $asOf, $group] = $this->window($criteria);

        return ['date_from' => $from->toISOString(), 'date_to' => $to->toISOString(), 'as_of' => $asOf->toISOString(), 'group_by' => $group];
    }

    public function backgroundExport(User $actor, Sensor $sensor, array $criteria, Closure $checkpoint): array
    {
        // Keep the CSV snapshot stable while status/cancellation/authorization use fresh reads.
        $connection = null;
        if (DB::getDriverName() === 'pgsql') {
            $connection = 'measurement_export_snapshot';
            config(['database.connections.'.$connection => DB::connection()->getConfig()]);
            DB::purge($connection);
        }
        $previous = $this->readConnection;
        $this->readConnection = $connection;
        try {
            return app(LocationAccess::class)->run($actor, fn () => $this->buildExport($actor, $sensor, $criteria, true, $checkpoint));
        } finally {
            $this->readConnection = $previous;
            if ($connection !== null) {
                DB::purge($connection);
            }
        }
    }

    private function buildExport(User $actor, Sensor $sensor, array $criteria, bool $background = false, ?Closure $checkpoint = null): array
    {
        $this->authorize($actor, $sensor);
        [$from,$to,$asOf] = $this->window($criteria);
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new ReportException('reports.errors.unavailable', 503);
        }
        try {
            $limit = $background ? self::MAX_BACKGROUND_EXPORT_ROWS : self::MAX_EXPORT_ROWS;
            $maxBytes = $background ? 134217728 : 12582912;
            $count = $this->snapshot(function () use ($stream, $sensor, $from, $to, $asOf, $limit, $maxBytes, $checkpoint, $background) {
                $sensor = Sensor::on($this->readConnection)->with(SensorAdministration::relations())->findOrFail($sensor->id);
                $count = 0;
                $total = DB::connection($this->readConnection)->query()->fromSub($this->query($sensor, $from, $to, $asOf)->reorder()->select('id')->limit($limit + 1), 'export_candidates')->count();
                if ($total > $limit) {
                    throw new ReportException('reports.errors.exportTooLarge');
                }
                $checkpoint?->__invoke(0, $total);
                if (fwrite($stream, "\xEF\xBB\xBF") === false) {
                    throw new ReportException('reports.errors.unavailable', 503);
                }
                $write = function (array $row) use ($stream) {
                    if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
                        throw new ReportException('reports.errors.unavailable', 503);
                    }
                };
                $write(['measurement_id', 'sensor_id', 'sensor_name', 'device_name', 'observed_at_utc', 'received_at_utc', 'value', 'unit', 'site_name', 'room_name', 'zone_name', 'asset_id', 'source_kind', 'source_id', 'assignment_revision_id', 'provenance_status', 'assignments_json', 'quality']);
                foreach ($this->readings($sensor, $from, $to, $asOf, $limit + 1, true, $background ? 250 : 1000) as $row) {
                    if ($count >= $limit) {
                        throw new ReportException('reports.errors.exportTooLarge');
                    }
                    if ($row->unit !== $sensor->measurementType->unit) {
                        throw new ReportException('reports.errors.unitMismatch', 409);
                    }
                    $location = $row->location_snapshot;
                    $write([$row->id, $sensor->id, $this->textCell($row->provenance_snapshot['sensorName'] ?? $sensor->name), $this->textCell($row->assignmentContext?->snapshot['asset']['name'] ?? $sensor->device->name), $row->observed_at->toISOString(), $row->received_at->toISOString(), $row->value, $this->textCell($row->unit), $this->textCell($location['site']['name'] ?? null), $this->textCell($location['room']['name'] ?? null), $this->textCell($location['zone']['name'] ?? null), $row->provenance_snapshot['assetId'] ?? null, $this->textCell($row->provenance_snapshot['source']['kind'] ?? null), $row->provenance_snapshot['source']['id'] ?? null, $row->asset_context_id, $row->provenance_snapshot['status'] ?? 'legacy', $row->assignmentContext ? json_encode(AssetContexts::snapshot($row->assignmentContext->snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null, $row->quality]);
                    $count++;
                    if ($count % 250 === 0) {
                        $checkpoint?->__invoke($count, $total);
                    }
                    if (ftell($stream) > $maxBytes) {
                        throw new ReportException('reports.errors.exportTooLarge');
                    }
                }

                $checkpoint?->__invoke($count, $total);
                if ($count !== $total) {
                    throw new ReportException('reports.errors.unavailable', 503);
                }

                return $count;
            });
            $this->authorize($actor, $sensor);
            rewind($stream);
            Log::notice('report.csv_exported', ['actor_uuid' => $actor->uuid, 'sensor_uuid' => $sensor->id, 'rows' => $count]);

            return ['stream' => $stream, 'size' => fstat($stream)['size'], 'filename' => 'measurements_'.$sensor->id.'_'.$from->format('Ymd_His').'_'.$to->format('Ymd_His').'.csv'];
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }
}
