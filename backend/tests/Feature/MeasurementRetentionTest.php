<?php

namespace Tests\Feature;

use App\Jobs\PurgeMeasurements;
use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\MeasurementPurgeRun;
use App\Models\MeasurementRetentionPolicy;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\MeasurementRetention;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeasurementRetentionTest extends TestCase
{
    use DatabaseMigrations;

    private const ROOT = '/api/v1/measurement-retention';

    private User $admin;

    private Sensor $sensor;

    private QueueManager $queueManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['queue.default' => 'database']);
        $this->queueManager = Queue::getFacadeRoot();
        Queue::fake();
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $asset = Asset::create(['name' => 'Retention fixture']);
        $type = MeasurementType::create(['name' => 'Retention temperature', 'code' => 'retention_temperature', 'unit' => '°C']);
        $this->sensor = Sensor::forceCreate(['name' => 'Retention channel', 'channel_key' => 'retention', 'asset_id' => $asset->id, 'measurement_type_id' => $type->id]);
    }

    protected function tearDown(): void
    {
        // Only isolated fixtures; historical migration guards remain authoritative.
        DB::table('alert_episodes')->delete();
        DB::table('alert_rules')->delete();
        DB::table('measurements')->delete();
        DB::table('measurement_purge_runs')->delete();
        DB::table('measurement_retention_policies')->update(['approved_at' => null]);
        parent::tearDown();
    }

    private function reading($observed, $received, ?string $id = null): void
    {
        DB::table('measurements')->insert(['id' => $id ?? (string) Str::uuid(), 'sensor_id' => $this->sensor->id, 'observed_at' => $observed->utc()->format('Y-m-d H:i:s.u'), 'received_at' => $received->utc()->format('Y-m-d H:i:s.u'), 'value' => '20', 'unit' => '°C']);
    }

    private function confirm(string $mode = 'manual'): MeasurementPurgeRun
    {
        $token = $this->actingAs($this->admin)->postJson(self::ROOT.'/preview', ['days' => 30, 'mode' => $mode, 'version' => MeasurementRetentionPolicy::findOrFail(1)->version])->assertOk()->json('data.token');
        $id = $this->postJson(self::ROOT.'/confirm', ['token' => $token, 'confirmed' => true])->assertAccepted()->json('data.id');

        return MeasurementPurgeRun::findOrFail($id);
    }

    public function test_unlimited_default_never_deletes_or_queues_and_preview_is_read_only(): void
    {
        $this->reading(now()->subYears(5), now()->subYears(5));
        $this->actingAs($this->admin)->getJson(self::ROOT)->assertOk()->assertJsonPath('data.policy.days', null)->assertJsonCount(0, 'data.runs');
        $this->artisan('measurements:retain')->assertSuccessful();
        $this->postJson(self::ROOT.'/preview', ['days' => 30, 'mode' => 'manual', 'version' => 1])->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('data.countIsExact', true);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('measurements', 1);
        $this->assertDatabaseCount('measurement_purge_runs', 0);
    }

    public function test_only_current_administrators_can_preview_or_confirm(): void
    {
        $this->getJson(self::ROOT)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(self::ROOT)->assertForbidden();
        $this->postJson(self::ROOT.'/preview', ['days' => 30, 'mode' => 'manual', 'version' => 1])->assertForbidden();
        $token = $this->actingAs($this->admin)->postJson(self::ROOT.'/preview', ['days' => 30, 'mode' => 'manual', 'version' => 1])->assertOk()->json('data.token');
        $this->postJson(self::ROOT.'/confirm', ['token' => $token])->assertUnprocessable();
        $other = User::factory()->create(['permission_role_ids' => $this->admin->permission_role_ids]);
        $this->actingAs($other)->postJson(self::ROOT.'/confirm', ['token' => $token, 'confirmed' => true])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('measurement_purge_runs', 0);
    }

    public function test_confirmation_is_idempotent_and_purges_only_strictly_expired_observation_and_receipt_pairs(): void
    {
        $cutoff = now()->subDays(30);
        $id = (string) Str::uuid();
        $this->reading($cutoff->copy()->subSecond(), $cutoff->copy()->subSecond(), $id);
        $this->reading(now(), now(), $id);
        $this->reading($cutoff, $cutoff->copy()->subSecond());
        $this->reading($cutoff->copy()->subSeconds(2), $cutoff);
        $this->reading($cutoff->copy()->subSeconds(3), now());
        $token = $this->actingAs($this->admin)->postJson(self::ROOT.'/preview', ['days' => 30, 'mode' => 'manual', 'version' => 1])->assertOk()->assertJsonPath('data.count', 1)->json('data.token');
        $runId = $this->postJson(self::ROOT.'/confirm', ['token' => $token, 'confirmed' => true])->assertAccepted()->json('data.id');
        $this->postJson(self::ROOT.'/confirm', ['token' => $token, 'confirmed' => true])->assertAccepted()->assertJsonPath('data.id', $runId);
        Queue::assertPushed(PurgeMeasurements::class, 1);
        $this->assertDatabaseCount('measurements', 5);
        $this->assertFalse(app(MeasurementRetention::class)->batch($runId));
        $this->assertDatabaseCount('measurements', 4);
        $this->assertSame(1, MeasurementPurgeRun::findOrFail($runId)->deleted_count);
        $this->assertNull(MeasurementRetentionPolicy::findOrFail(1)->days);
        $this->assertFalse(app(MeasurementRetention::class)->batch($runId));
        $this->assertDatabaseCount('measurements', 4);
    }

    public function test_alert_snapshots_survive_while_expired_raw_readings_disappear_from_reports(): void
    {
        $this->reading(now()->subDays(31), now()->subDays(31));
        $rule = AlertRule::create(['sensor_id' => $this->sensor->id, 'name' => 'Retained alert snapshot', 'kind' => 'threshold', 'severity' => 'warning', 'minimum' => 25, 'max_age_seconds' => 3456000, 'delay_seconds' => 0, 'activated_at' => now()->subDays(32)]);
        app(AlertEvaluator::class)->evaluate($rule);
        $episode = AlertEpisode::sole();
        $snapshot = $episode->getAttributes();
        $run = $this->confirm();
        $this->assertFalse(app(MeasurementRetention::class)->batch($run->id));
        $this->assertSame($snapshot, $episode->fresh()->getAttributes());
        $this->getJson('/api/v1/sensors/'.$this->sensor->id.'/measurements')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.latest', null);
        $this->getJson('/api/v1/reports/sensors/'.$this->sensor->id)->assertOk()->assertJsonPath('data.statistics.count', 0);
    }

    public function test_batches_are_bounded_and_revoked_sessions_stop_pending_deletion(): void
    {
        for ($i = 0; $i < 251; $i++) {
            $this->reading(now()->subDays(31)->subSeconds($i), now()->subDays(31));
        }
        $run = $this->confirm();
        $this->assertTrue(app(MeasurementRetention::class)->batch($run->id));
        $this->assertDatabaseCount('measurements', 1);
        $this->assertSame(250, $run->fresh()->deleted_count);
        $this->admin->increment('session_version');
        $this->assertFalse(app(MeasurementRetention::class)->batch($run->id));
        $this->assertDatabaseCount('measurements', 1);
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_disabling_policy_cancels_its_work_and_daily_schedule_is_idempotent(): void
    {
        $this->reading(now()->subDays(31), now()->subDays(31));
        $run = $this->confirm('policy');
        $this->assertSame(30, MeasurementRetentionPolicy::findOrFail(1)->days);
        $this->patchJson(self::ROOT, ['version' => 2])->assertOk()->assertJsonPath('data.policy.days', null);
        $this->assertFalse(app(MeasurementRetention::class)->batch($run->id));
        $this->assertDatabaseCount('measurements', 1);
        $run = $this->confirm('policy');
        $this->assertFalse(app(MeasurementRetention::class)->batch($run->id));
        $this->artisan('measurements:retain')->assertSuccessful();
        $this->artisan('measurements:retain')->assertSuccessful();
        $this->assertDatabaseCount('measurement_purge_runs', 3);
    }

    public function test_stale_preview_invalid_ages_and_explicit_cancellation_preserve_data(): void
    {
        $this->actingAs($this->admin);
        foreach ([0, 36501, 1.5] as $days) {
            $this->postJson(self::ROOT.'/preview', ['days' => $days, 'mode' => 'manual', 'version' => 1])->assertUnprocessable();
        }
        $token = $this->postJson(self::ROOT.'/preview', ['days' => 30, 'mode' => 'manual', 'version' => 1])->assertOk()->json('data.token');
        $this->patchJson(self::ROOT, ['version' => 1])->assertOk();
        $this->postJson(self::ROOT.'/confirm', ['token' => $token, 'confirmed' => true])->assertConflict();
        $this->reading(now()->subDays(31), now()->subDays(31));
        $run = $this->confirm();
        $this->postJson(self::ROOT.'/runs/'.$run->id.'/cancel')->assertOk();
        $this->assertFalse(app(MeasurementRetention::class)->batch($run->id));
        $this->assertDatabaseCount('measurements', 1);
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_real_queue_runs_bounded_batches_and_replay_never_deletes_twice(): void
    {
        if (getenv('REDIS_TESTS') !== '1') {
            $this->markTestSkipped('Requires disposable Redis DBs14/15.');
        }
        config(['queue.default' => 'redis', 'cache.default' => 'redis', 'database.redis.cache.database' => 14]);
        Cache::flush();
        Queue::swap($this->queueManager);
        Queue::connection()->clear('default');
        for ($i = 0; $i < 251; $i++) {
            $this->reading(now()->subDays(31)->subSeconds($i), now()->subDays(31));
        }
        $run = $this->confirm();
        $this->assertSame(1, Queue::connection()->size('default'));
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'default', '--once' => true, '--sleep' => 0]);
        $this->assertDatabaseCount('measurements', 1);
        $this->assertSame(250, $run->fresh()->deleted_count);
        $this->assertSame(1, Queue::connection()->size('default'));
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'default', '--once' => true, '--sleep' => 0]);
        $this->assertDatabaseCount('measurements', 0);
        $this->assertSame(251, $run->fresh()->deleted_count);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(0, Queue::connection()->size('default'));
        $this->assertFalse(app(MeasurementRetention::class)->batch($run->id));
        $this->assertSame(251, $run->fresh()->deleted_count);
    }

    public function test_expired_or_stale_preview_cannot_start_and_sync_driver_is_rejected(): void
    {
        $token = $this->actingAs($this->admin)->postJson(self::ROOT.'/preview', ['days' => 30, 'mode' => 'manual', 'version' => 1])->assertOk()->json('data.token');
        $this->travel(601)->seconds();
        $this->postJson(self::ROOT.'/confirm', ['token' => $token, 'confirmed' => true])->assertUnprocessable();
        $token = $this->postJson(self::ROOT.'/preview', ['days' => 30, 'mode' => 'manual', 'version' => 1])->assertOk()->json('data.token');
        foreach (['sync', 'null', 'deferred', 'background', 'failover'] as $driver) {
            config(['queue.default' => $driver]);
            $this->postJson(self::ROOT.'/confirm', ['token' => $token, 'confirmed' => true])->assertStatus(503);
        }
        $this->assertDatabaseCount('measurement_purge_runs', 0);
        Queue::assertNothingPushed();
    }
}
