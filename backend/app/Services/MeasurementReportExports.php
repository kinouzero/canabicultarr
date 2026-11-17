<?php

namespace App\Services;

use App\Exceptions\ReportException;
use App\Jobs\GenerateMeasurementReport;
use App\Models\InstanceSetting;
use App\Models\MeasurementReportExport;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MeasurementReportExports
{
    public const MAX_PDF_ROWS = 1000000;

    public function fingerprint(User $user): string
    {
        $user->load('permissionRoles');

        return hash_hmac('sha256', json_encode([$user->uuid, $user->session_version, $user->is_active, $user->isAdministrator(), $user->effectiveOperationalPermissions(), $user->location_access], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function fresh(User $actor): User
    {
        $current = User::find($actor->id);
        if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }

        return $current;
    }

    private function access(MeasurementReportExport $export): array
    {
        $user = User::find($export->requested_by);
        if (! $user || ! $user->is_active || $user->session_version !== $export->actor_session_version || ! hash_equals($export->access_fingerprint, $this->fingerprint($user))) {
            throw new AuthorizationException;
        }
        app(LocationAccess::class)->clear();
        if (($export->criteria['kind'] ?? null) === 'culture') {
            Gate::forUser($user)->authorize('dashboard.view');
            if (! empty($export->criteria['site_id'])) {
                if (! app(LocationAccess::class)->run($user, fn () => Site::find($export->criteria['site_id']))) {
                    throw new AuthorizationException;
                }
            }

            return [$user, null];
        }
        $sensor = app(LocationAccess::class)->run($user, fn () => Sensor::with(SensorAdministration::relations())->find($export->sensor_id));
        if (! $sensor) {
            throw new AuthorizationException;
        }
        Gate::forUser($user)->authorize('view', $sensor);

        return [$user, $sensor];
    }

    private function queuedDriver(): void
    {
        if (! in_array(config('queue.connections.'.config('queue.default').'.driver'), ['redis', 'database', 'sqs', 'beanstalkd'], true)) {
            throw new ReportException('reportExports.errors.queueUnavailable', 503);
        }
    }

    public function create(User $actor, ?Sensor $sensor, array $data): MeasurementReportExport
    {
        $this->queuedDriver();
        $actor = $this->fresh($actor);
        $sensor ? Gate::forUser($actor)->authorize('view', $sensor) : Gate::forUser($actor)->authorize('dashboard.view');
        if (! $sensor && ! empty($data['site_id'])) {
            app(LocationAccess::class)->run($actor, fn () => Site::findOrFail($data['site_id']));
        }
        $data['request_key'] = strtolower($data['request_key']);
        $criteria = $sensor ? app(SensorReporting::class)->normalizeCriteria($data) : ['kind' => 'culture', 'site_id' => $data['site_id'] ?? null, 'days' => $data['days'], 'sections' => $data['sections']];
        $disk = config('documents.disk');
        if (! in_array($disk, app(DocumentStorage::class)->disks(), true)) {
            throw new ReportException('reportExports.errors.failed', 503);
        }

        try {
            return Cache::lock('report-exports:admission', 10)->block(3, fn () => DB::transaction(function () use ($actor, $sensor, $data, $criteria, $disk) {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if (! $current->is_active || $current->session_version !== $actor->session_version) {
                    throw new AuthenticationException;
                }
                app(LocationAccess::class)->clear();
                $sensor ? Gate::forUser($current)->authorize('view', $sensor) : Gate::forUser($current)->authorize('dashboard.view');
                if ($existing = MeasurementReportExport::where('requested_by', $current->id)->where('request_key', $data['request_key'])->first()) {
                    if ($existing->sensor_id !== $sensor?->id || $existing->format !== $data['format'] || $existing->criteria !== $criteria || $existing->locale !== $data['locale'] || $existing->timezone !== $data['timezone']) {
                        throw new ReportException('reportExports.errors.requestConflict', 409);
                    }

                    return $existing;
                }
                $retained = MeasurementReportExport::where('expires_at', '>', now()->utc()->format('Y-m-d H:i:s.u'));
                if ((clone $retained)->whereIn('status', ['queued', 'processing'])->count() >= 20 || (clone $retained)->count() >= 100
                    || (clone $retained)->where('requested_by', $current->id)->count() >= 10
                    || (clone $retained)->where('requested_by', $current->id)->whereIn('status', ['queued', 'processing'])->count() >= 2) {
                    throw new ReportException('reportExports.errors.busy', 429);
                }
                $settings = InstanceSetting::findOrFail(1);
                $export = MeasurementReportExport::create(['requested_by' => $current->id, 'actor_uuid' => $current->uuid, 'actor_session_version' => $current->session_version,
                    'request_key' => $data['request_key'], 'access_fingerprint' => $this->fingerprint($current), 'sensor_id' => $sensor?->id, 'criteria' => $criteria,
                    'display' => ['dateFormat' => $settings->date_format, 'timeFormat' => $settings->time_format, 'siteTitle' => $settings->site_title],
                    'format' => $data['format'], 'locale' => $data['locale'], 'timezone' => $data['timezone'], 'disk' => $disk, 'expires_at' => now()->addDay()]);
                DB::afterCommit(function () use ($export) {
                    try {
                        GenerateMeasurementReport::dispatch($export->id)->onQueue('exports');
                    } catch (Throwable) {
                        $export->update(['status' => 'failed', 'completed_at' => now(), 'error_code' => 'reportExports.errors.failed']);
                        throw new ReportException('reportExports.errors.failed', 503);
                    }
                });
                Log::notice('report.export_queued', ['actor_uuid' => $current->uuid, 'export_uuid' => $export->id, 'sensor_uuid' => $sensor?->id, 'format' => $export->format]);

                return $export;
            }));
        } catch (AuthenticationException|AuthorizationException|ReportException $exception) {
            throw $exception;
        } catch (Throwable) {
            Log::warning('report.export_request_failed', ['actor_uuid' => $actor->uuid]);
            throw new ReportException('reportExports.errors.failed', 503);
        }
    }

    public function listing(User $actor, ?Sensor $sensor): array
    {
        $current = $this->fresh($actor);
        $sensor ? Gate::forUser($current)->authorize('view', $sensor) : Gate::forUser($current)->authorize('dashboard.view');

        $fingerprint = $this->fingerprint($current);

        return MeasurementReportExport::where('requested_by', $current->id)->when($sensor, fn ($q) => $q->where('sensor_id', $sensor->id), fn ($q) => $q->where('criteria->kind', 'culture'))->latest('created_at')->orderByDesc('id')->limit(10)->get()->map(fn ($export) => $this->payload($export, $fingerprint))->all();
    }

    public function payload(MeasurementReportExport $export, ?string $fingerprint = null): array
    {
        $status = $export->expires_at->lessThanOrEqualTo(now()) ? 'expired' : $export->status;

        return ['id' => $export->id, 'sensorId' => $export->sensor_id, 'format' => $export->format, 'status' => $status, 'canDownload' => $status === 'ready' && $fingerprint !== null && hash_equals($export->access_fingerprint, $fingerprint), 'progress' => $export->progress,
            'processedRows' => $export->processed_rows, 'totalRows' => $export->total_rows, 'bytes' => $export->bytes, 'errorCode' => $export->error_code,
            'createdAt' => $export->created_at->toISOString(), 'expiresAt' => $export->expires_at->toISOString()];
    }

    public function path(MeasurementReportExport $export): string
    {
        return 'measurement-exports/'.$export->id.'.'.$export->format;
    }

    private function disk(MeasurementReportExport $export): Filesystem
    {
        if (! in_array($export->disk, app(DocumentStorage::class)->disks(), true)) {
            throw new ReportException('reportExports.errors.failed', 503);
        }

        return Storage::disk($export->disk);
    }

    private function removeArtifact(MeasurementReportExport $export): bool
    {
        try {
            return $this->disk($export)->delete($this->path($export));
        } catch (Throwable) {
            Log::warning('report.export_cleanup_failed', ['export_uuid' => $export->id]);

            return false;
        }
    }

    public function cancel(User $actor, MeasurementReportExport $export): void
    {
        $current = $this->fresh($actor);
        if ($export->requested_by !== $current->id) {
            throw new AuthorizationException;
        }
        $export->update(['status' => 'cancelled', 'completed_at' => now()]);
        if (! $this->removeArtifact($export)) {
            throw new ReportException('reportExports.errors.failed', 503);
        }
    }

    public function download(User $actor, MeasurementReportExport $export): array
    {
        $current = $this->fresh($actor);
        if ($export->requested_by !== $current->id) {
            throw new AuthorizationException;
        }
        $this->access($export);
        if ($export->expires_at->lessThanOrEqualTo(now()) || $export->status === 'expired') {
            throw new ReportException('reportExports.errors.expired', 410);
        }
        if ($export->status !== 'ready') {
            throw new ReportException('reportExports.errors.notReady', 409);
        }
        try {
            $stream = $this->disk($export)->readStream($this->path($export));
        } catch (Throwable) {
            Log::warning('report.export_read_failed', ['export_uuid' => $export->id]);
            throw new ReportException('reportExports.errors.failed', 503);
        }
        if (! is_resource($stream)) {
            throw new ReportException('reportExports.errors.failed', 503);
        }
        try {
            $this->access($export);
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }

        return ['stream' => $stream, 'bytes' => $export->bytes, 'filename' => (($export->criteria['kind'] ?? null) === 'culture' ? 'culture_' : 'measurements_').$export->id.'.'.$export->format, 'mime' => $export->format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8'];
    }

    public function generate(string $id): void
    {
        $export = MeasurementReportExport::findOrFail($id);
        if (! in_array($export->status, ['queued', 'processing'], true)) {
            return;
        }
        $stream = null;
        $started = microtime(true);
        $lastCheck = 0;
        try {
            [$actor, $sensor] = $this->access($export);
            if ($export->expires_at->lessThanOrEqualTo(now())) {
                $removed = $this->removeArtifact($export);
                $export->update(['status' => $removed ? 'expired' : 'failed']);

                return;
            }
            if (! MeasurementReportExport::whereKey($export->id)->whereIn('status', ['queued', 'processing'])->where('expires_at', '>', now()->utc()->format('Y-m-d H:i:s.u'))->update(['status' => 'processing', 'progress' => 0, 'processed_rows' => 0, 'error_code' => null])) {
                return;
            }
            $checkpoint = function (int $processed, ?int $total, ?int $percent = null) use ($export, $started, &$lastCheck): void {
                if (microtime(true) - $started > 35) {
                    throw new ReportException('reportExports.errors.tooLarge');
                }
                if (microtime(true) - $lastCheck < .25 && ($total === null || $processed < $total)) {
                    return;
                }
                $lastCheck = microtime(true);
                $this->access($export);
                $current = $export->fresh();
                if ($current->status !== 'processing' || $current->expires_at->lessThanOrEqualTo(now())) {
                    throw new ReportException('reportExports.errors.cancelled');
                }
                $progress = $percent ?? ($total ? (int) floor($processed / $total * 90) + 5 : 5);
                if (! MeasurementReportExport::whereKey($export->id)->where('status', 'processing')->update(['progress' => min(95, max(0, $progress)), 'processed_rows' => $processed, 'total_rows' => $total])) {
                    throw new ReportException('reportExports.errors.cancelled');
                }
            };
            $checkpoint(0, null, 5);
            if (($export->criteria['kind'] ?? null) === 'culture') {
                $stream = app(CultureOverviewExport::class)->generate($actor, $export, $checkpoint);
            } elseif ($export->format === 'csv') {
                $data = app(SensorReporting::class)->backgroundExport($actor, $sensor, $export->criteria, $checkpoint);
                $stream = $data['stream'];
            } else {
                $report = app(SensorReporting::class)->report($actor, $sensor, $export->criteria);
                if ($report['statistics']['count'] > self::MAX_PDF_ROWS) {
                    throw new ReportException('reportExports.errors.tooLarge');
                }
                $checkpoint($report['statistics']['count'], $report['statistics']['count'], 35);
                $pdf = app(MeasurementReportPdf::class)->render($report, $export);
                $checkpoint($report['statistics']['count'], $report['statistics']['count'], 85);
                $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
                if ($stream === false || fwrite($stream, $pdf) !== strlen($pdf)) {
                    throw new ReportException('reportExports.errors.failed', 503);
                }
                rewind($stream);
            }
            $bytes = fstat($stream)['size'];
            $this->access($export);
            if ($export->fresh()->status !== 'processing' || $export->expires_at->lessThanOrEqualTo(now())) {
                throw new ReportException('reportExports.errors.cancelled');
            }
            if (! $this->disk($export)->put($this->path($export), $stream, ['visibility' => 'private'])) {
                throw new ReportException('reportExports.errors.failed', 503);
            }
            $this->access($export);
            if (! MeasurementReportExport::whereKey($export->id)->where('status', 'processing')->where('expires_at', '>', now()->utc()->format('Y-m-d H:i:s.u'))->update(['status' => 'ready', 'progress' => 100, 'bytes' => $bytes, 'completed_at' => now()])) {
                throw new ReportException('reportExports.errors.cancelled');
            }
            Log::notice('report.export_ready', ['export_uuid' => $export->id, 'bytes' => $bytes]);
        } catch (AuthenticationException|AuthorizationException) {
            $export->update(['status' => 'cancelled', 'error_code' => 'reportExports.errors.accessLost', 'completed_at' => now()]);
            $this->removeArtifact($export);
        } catch (ReportException $exception) {
            if ($exception->status >= 500) {
                throw new ReportException('reportExports.errors.failed', 503);
            }
            $cancelled = $exception->errorCode === 'reportExports.errors.cancelled';
            MeasurementReportExport::whereKey($export->id)->whereIn('status', ['queued', 'processing'])->update(['status' => $cancelled ? 'cancelled' : 'failed', 'error_code' => $exception->errorCode, 'completed_at' => now()]);
            $this->removeArtifact($export);
        } catch (Throwable) {
            Log::warning('report.export_generation_failed', ['export_uuid' => $export->id]);
            throw new ReportException('reportExports.errors.failed', 503);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function cleanup(): int
    {
        $until = microtime(true) + 35;
        $count = 0;
        foreach (MeasurementReportExport::where('expires_at', '<=', now()->utc()->format('Y-m-d H:i:s.u'))->where('status', '!=', 'expired')->orderBy('expires_at')->limit(20)->get() as $export) {
            if (microtime(true) > $until) {
                break;
            }
            try {
                if (! $this->removeArtifact($export)) {
                    continue;
                }
                $export->update(['status' => 'expired']);
                $count++;
            } catch (Throwable) {
                Log::warning('report.export_cleanup_failed', ['export_uuid' => $export->id]);
            }
        }

        return $count;
    }
}
