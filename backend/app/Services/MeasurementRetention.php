<?php

namespace App\Services;

use App\Exceptions\InstanceSettingsException;
use App\Jobs\PurgeMeasurements;
use App\Models\MeasurementPurgeRun;
use App\Models\MeasurementRetentionPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MeasurementRetention
{
    public const PREVIEW_LIMIT = 100000;

    public const BATCH_SIZE = 250;

    private function actor(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->first();
        if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }
        Gate::forUser($current)->authorize('instance-settings.manage');
        app(LocationAccess::class)->assertGlobal($current);

        return $current;
    }

    private function assertAsyncQueue(): void
    {
        if (! in_array(config('queue.connections.'.config('queue.default').'.driver'), ['redis', 'database', 'beanstalkd', 'sqs'], true)) {
            throw new InstanceSettingsException('retention.errors.queueUnavailable', 503);
        }
    }

    private function candidates(CarbonImmutable $cutoff): Builder
    {
        // Global administration only. Recent receipts protect delayed/backfilled samples.
        return DB::table('measurements')->where('observed_at', '<', $cutoff->format('Y-m-d H:i:s.u'))->where('received_at', '<', $cutoff->format('Y-m-d H:i:s.u'));
    }

    public function show(User $actor): array
    {
        $this->actor($actor);
        $policy = MeasurementRetentionPolicy::findOrFail(1);

        return ['policy' => ['days' => $policy->days, 'version' => $policy->version, 'approvedAt' => $policy->approved_at?->toISOString()],
            'runs' => MeasurementPurgeRun::latest('created_at')->orderByDesc('id')->limit(5)->get()->map($this->runPayload(...))->all()];
    }

    public function preview(User $actor, array $data): array
    {
        $this->actor($actor);
        $policy = MeasurementRetentionPolicy::findOrFail(1);
        if ($policy->version !== (int) $data['version']) {
            throw new InstanceSettingsException('retention.errors.conflict');
        }
        $data['days'] = (int) $data['days'];
        $cutoff = CarbonImmutable::now('UTC')->subDays($data['days']);
        $count = DB::query()->fromSub($this->candidates($cutoff)->select('id')->limit(self::PREVIEW_LIMIT + 1), 'expired_samples')->count();
        $token = Str::random(64);
        Cache::put('measurement-retention:preview:'.hash('sha256', $token), ['actor' => $actor->uuid, 'session' => $actor->session_version,
            'version' => $policy->version, 'days' => $data['days'], 'mode' => $data['mode'], 'cutoffAt' => $cutoff->toISOString()], 600);

        return ['token' => $token, 'mode' => $data['mode'], 'days' => $data['days'], 'cutoffAt' => $cutoff->toISOString(), 'count' => min($count, self::PREVIEW_LIMIT), 'countIsExact' => $count <= self::PREVIEW_LIMIT, 'expiresAt' => now()->addMinutes(10)->utc()->toISOString()];
    }

    public function confirm(User $actor, string $token): MeasurementPurgeRun
    {
        $key = hash('sha256', $token);
        $run = DB::transaction(function () use ($actor, $key) {
            $policy = MeasurementRetentionPolicy::whereKey(1)->lockForUpdate()->firstOrFail();
            $current = $this->actor($actor);
            if ($existing = MeasurementPurgeRun::where('confirmation_key', $key)->first()) {
                if ($existing->actor_uuid !== $current->uuid || $existing->actor_session_version !== $current->session_version) {
                    throw new AuthorizationException;
                }

                return $existing;
            }
            $preview = Cache::get('measurement-retention:preview:'.$key);
            if (! is_array($preview) || $preview['actor'] !== $current->uuid || $preview['session'] !== $current->session_version) {
                throw new InstanceSettingsException('retention.errors.previewExpired', 422);
            }
            if ($policy->version !== $preview['version']) {
                throw new InstanceSettingsException('retention.errors.conflict');
            }
            if (MeasurementPurgeRun::whereIn('status', ['queued', 'running'])->exists()) {
                throw new InstanceSettingsException('retention.errors.busy');
            }
            $this->assertAsyncQueue();
            if ($preview['mode'] === 'policy') {
                $policy->fill(['days' => $preview['days'], 'approved_by' => $current->id, 'approved_session_version' => $current->session_version, 'approved_at' => now(), 'version' => $policy->version + 1])->save();
            }
            $run = MeasurementPurgeRun::create(['confirmation_key' => $key, 'requested_by' => $current->id, 'actor_uuid' => $current->uuid, 'actor_session_version' => $current->session_version,
                'mode' => $preview['mode'], 'days' => $preview['days'], 'policy_version' => $policy->version, 'cutoff_at' => $preview['cutoffAt']]);
            DB::afterCommit(fn () => $this->enqueue($run));
            Log::notice('measurement.retention_confirmed', ['actor_uuid' => $current->uuid, 'run_uuid' => $run->id, 'mode' => $run->mode, 'days' => $run->days, 'cutoff_at' => $run->cutoff_at->toISOString()]);

            return $run;
        });
        Cache::forget('measurement-retention:preview:'.$key);

        return $run;
    }

    private function enqueue(MeasurementPurgeRun $run): void
    {
        try {
            PurgeMeasurements::dispatch($run->id)->onQueue('default');
        } catch (Throwable) {
            $run->update(['status' => 'failed', 'completed_at' => now(), 'error_code' => 'retention.errors.failed']);
            throw new InstanceSettingsException('retention.errors.failed', 503);
        }
    }

    public function disable(User $actor, int $version): void
    {
        DB::transaction(function () use ($actor, $version) {
            $policy = MeasurementRetentionPolicy::whereKey(1)->lockForUpdate()->firstOrFail();
            $current = $this->actor($actor);
            if ($policy->version !== $version) {
                throw new InstanceSettingsException('retention.errors.conflict');
            }
            $policy->fill(['days' => null, 'approved_by' => null, 'approved_session_version' => null, 'approved_at' => null, 'version' => $policy->version + 1])->save();
            Log::notice('measurement.retention_disabled', ['actor_uuid' => $current->uuid]);
        });
    }

    public function cancel(User $actor, MeasurementPurgeRun $run): void
    {
        DB::transaction(function () use ($actor, $run) {
            MeasurementRetentionPolicy::whereKey(1)->lockForUpdate()->firstOrFail();
            $current = $this->actor($actor);
            MeasurementPurgeRun::whereKey($run->id)->whereIn('status', ['queued', 'running'])->update(['status' => 'cancelled', 'completed_at' => now()]);
            Log::notice('measurement.purge_cancelled', ['actor_uuid' => $current->uuid, 'run_uuid' => $run->id]);
        });
    }

    public function batch(string $runId): bool
    {
        return DB::transaction(function () use ($runId) {
            $policy = MeasurementRetentionPolicy::whereKey(1)->lockForUpdate()->firstOrFail();
            $run = MeasurementPurgeRun::whereKey($runId)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return false;
            }
            $user = User::find($run->requested_by);
            try {
                if (! $user || $user->session_version !== $run->actor_session_version) {
                    throw new AuthenticationException;
                }
                $this->actor($user);
                if ($run->mode === 'policy' && ($policy->days === null || $policy->approved_at === null || $policy->version !== $run->policy_version)) {
                    throw new AuthorizationException;
                }
            } catch (AuthenticationException|AuthorizationException) {
                $run->update(['status' => 'cancelled', 'completed_at' => now(), 'error_code' => 'retention.errors.authorization']);

                return false;
            }
            $this->assertAsyncQueue();
            $rows = $this->candidates($run->cutoff_at)->orderBy('observed_at')->orderBy('id')->limit(self::BATCH_SIZE)->get(['id', 'observed_at']);
            $deleted = 0;
            if ($rows->isNotEmpty()) {
                $deleted = $this->candidates($run->cutoff_at)->where(function ($query) use ($rows) {
                    foreach ($rows as $row) {
                        $query->orWhere(fn ($key) => $key->where('id', $row->id)->where('observed_at', $row->observed_at));
                    }
                })->delete();
            }
            $more = $rows->count() === self::BATCH_SIZE;
            $run->fill(['status' => $more ? 'running' : 'completed', 'deleted_count' => $run->deleted_count + $deleted, 'completed_at' => $more ? null : now()])->save();
            Log::notice('measurement.purge_batch', ['run_uuid' => $run->id, 'deleted' => $deleted, 'completed' => ! $more]);

            return $more;
        });
    }

    public function schedule(): void
    {
        DB::transaction(function () {
            $policy = MeasurementRetentionPolicy::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($policy->days === null || $policy->approved_at === null || MeasurementPurgeRun::whereIn('status', ['queued', 'running'])->exists()) {
                return;
            }
            $actor = User::find($policy->approved_by);
            if (! $actor || $actor->session_version !== $policy->approved_session_version) {
                return;
            }
            try {
                $this->actor($actor);
            } catch (AuthenticationException|AuthorizationException) {
                return;
            }
            $this->assertAsyncQueue();
            $key = hash('sha256', 'automatic:'.$policy->version.':'.now()->utc()->toDateString());
            if (MeasurementPurgeRun::where('confirmation_key', $key)->exists()) {
                return;
            }
            $run = MeasurementPurgeRun::create(['confirmation_key' => $key, 'requested_by' => $actor->id, 'actor_uuid' => $actor->uuid, 'actor_session_version' => $actor->session_version,
                'mode' => 'policy', 'days' => $policy->days, 'policy_version' => $policy->version, 'cutoff_at' => CarbonImmutable::now('UTC')->subDays($policy->days)]);
            DB::afterCommit(fn () => $this->enqueue($run));
        });
    }

    public function runPayload(MeasurementPurgeRun $run): array
    {
        return ['id' => $run->id, 'mode' => $run->mode, 'days' => $run->days, 'cutoffAt' => $run->cutoff_at->toISOString(), 'status' => $run->status,
            'deletedCount' => $run->deleted_count, 'createdAt' => $run->created_at->toISOString(), 'completedAt' => $run->completed_at?->toISOString(), 'errorCode' => $run->error_code];
    }
}
