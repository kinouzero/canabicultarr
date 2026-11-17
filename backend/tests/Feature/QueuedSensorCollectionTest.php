<?php

namespace Tests\Feature;

use App\Jobs\CollectRemoteSensor;
use App\Jobs\StoreRemoteSensorEvent;
use App\Models\ConnectionSetting;
use App\Models\HomeAssistantLink;
use App\Models\Measurement;
use App\Models\Site;
use App\Models\User;
use App\Services\ConnectionConfiguration;
use App\Services\HomeAssistantClient;
use App\Services\LocationAccess;
use App\Services\RemoteProviderEvents;
use App\Services\RemoteSensorCollection;
use App\Services\RemoteSensorImport;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueuedSensorCollectionTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        if (getenv('REDIS_TESTS') === '1') {
            DB::table('measurements')->delete();
        }
        parent::tearDown();
    }

    private User $admin;

    private HomeAssistantLink $link;

    private array $state;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('REDIS_TESTS') !== '1') {
            $this->markTestSkipped('Requires isolated Redis DBs14/15.');
        }
        config(['queue.default' => 'redis', 'cache.default' => 'redis', 'database.redis.cache.database' => 14]);
        Cache::flush();
        Queue::connection()->clear('integrations');
        $this->freezeTime();
        foreach (['HOME_ASSISTANT_URL', 'HOME_ASSISTANT_TOKEN'] as $field) {
            config(['connection_settings.home_assistant.'.$field.'.locked' => false]);
        }
        ConnectionSetting::findOrFail('home_assistant')->update(['values' => ['HOME_ASSISTANT_URL' => 'https://ha.example.test', 'HOME_ASSISTANT_TOKEN' => 'fictional-provider-secret']]);
        app(ConnectionConfiguration::class)->apply();
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->state = ['entity_id' => 'sensor.temperature', 'state' => '21.5', 'attributes' => ['friendly_name' => 'Probe', 'unit_of_measurement' => '°C', 'device_class' => 'temperature'], 'last_reported' => now()->subSecond()->toISOString()];
        Http::preventStrayRequests();
        Http::fake(['https://ha.example.test/api/states' => Http::response([$this->state]), 'https://ha.example.test/api/template' => Http::response([['entity_id' => 'sensor.temperature', 'device_id' => 'probe', 'name' => 'Probe']]), 'https://ha.example.test/api/states/*' => Http::response($this->state)]);
        app(RemoteSensorImport::class)->import($this->admin, ['sensor.temperature']);
        $this->link = HomeAssistantLink::sole();
        $this->pollResponses(200);
    }

    private function pollResponses(int $status): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://ha.example.test/api/states/*' => fn () => Http::response($status === 200 ? $this->state : ['private' => 'fictional-provider-secret'], $status)]);
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'integrations', '--once' => true, '--sleep' => 0]);
    }

    public function test_manual_and_scheduled_polls_share_one_unique_job_and_permanent_samples(): void
    {
        $root = '/api/v1/integrations/home-assistant/links/'.$this->link->id.'/collect';
        $this->actingAs($this->admin)->postJson($root)->assertAccepted()->assertExactJson(['data' => ['outcome' => 'queued', 'code' => null]]);
        $this->artisan('measurements:collect')->assertSuccessful();
        $this->postJson($root)->assertAccepted();
        $this->assertSame(1, Queue::connection()->size('integrations'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('measurements', 0);
        $this->work();
        $this->assertDatabaseCount('measurements', 1);
        Http::assertSentCount(1);
        $this->assertSame('home_assistant', Measurement::sole()->provenance_snapshot['source']['kind']);
        $this->postJson($root)->assertAccepted();
        $this->work();
        $this->assertDatabaseCount('measurements', 1);
        $this->assertSame(0, Queue::connection()->size('integrations'), Artisan::output());
    }

    public function test_transient_failure_is_retried_with_backoff_and_then_recovers(): void
    {
        app(RemoteSensorCollection::class)->enqueue(new HomeAssistantClient, $this->link, $this->admin);
        $this->pollResponses(503);
        $this->work();
        $this->assertSame('measurements.errors.providerUnavailable', $this->link->fresh()->last_error);
        $this->assertSame(1, Queue::connection()->size('integrations'));
        $this->assertDatabaseCount('measurements', 0);
        $this->pollResponses(200);
        $this->travel(16)->seconds();
        $this->work();
        $this->assertDatabaseCount('measurements', 1);
        $this->assertNull($this->link->fresh()->last_error);
        $this->assertSame(0, Queue::connection()->size('integrations'), Artisan::output());
    }

    public function test_access_denial_is_not_retried_and_exhaustion_has_only_sanitized_diagnostics(): void
    {
        $collection = app(RemoteSensorCollection::class);
        $collection->enqueue(new HomeAssistantClient, $this->link, $this->admin);
        $this->pollResponses(401);
        $this->work();
        $this->assertSame('measurements.errors.accessDenied', $this->link->fresh()->last_error);
        $this->assertSame(0, Queue::connection()->size('integrations'), Artisan::output());
        $collection->enqueue(new HomeAssistantClient, $this->link, $this->admin);
        $this->pollResponses(503);
        foreach ([0, 16, 61] as $delay) {
            $this->travel($delay)->seconds();
            $this->work();
        }
        Http::assertSentCount(3);
        $this->assertSame(0, Queue::connection()->size('integrations'), Artisan::output());
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertStringNotContainsString('fictional-provider-secret', DB::table('failed_jobs')->value('exception'));
        $this->assertSame('measurements.errors.providerUnavailable', $this->link->fresh()->last_error);
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_automatic_failure_waits_for_next_interval_and_pending_work_respects_pause(): void
    {
        $collection = app(RemoteSensorCollection::class);
        $collection->batch('ha');
        $this->pollResponses(503);
        $this->work();
        $this->travel(16)->seconds();
        $this->work();
        Http::assertSentCount(1);
        $this->assertSame(0, Queue::connection()->size('integrations'));
        $this->assertSame(0, $collection->batch('ha'));
        $this->travel(44)->seconds();
        $this->assertSame(1, $collection->batch('ha'));
        $row = ConnectionSetting::findOrFail('home_assistant');
        $row->update(['values' => [...$row->values, 'HOME_ASSISTANT_SYNC_ENABLED' => false]]);
        $this->work();
        Http::assertSentCount(1);
        $this->assertSame(0, Queue::connection()->size('integrations'));
        $row->update(['values' => array_diff_key($row->values, ['HOME_ASSISTANT_SYNC_ENABLED' => true])]);
        app(ConnectionConfiguration::class)->apply();
        $this->pollResponses(200);
        $this->assertSame(1, $collection->batch('ha'));
        $this->work();
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_event_jobs_deduplicate_share_poll_mutex_and_leave_reconciliation_due(): void
    {
        $row = ConnectionSetting::findOrFail('home_assistant');
        $row->update(['values' => [...$row->values, 'HOME_ASSISTANT_EVENTS_ENABLED' => true]]);
        config(['connection_settings.home_assistant.HOME_ASSISTANT_EVENTS_ENABLED.locked' => false]);
        app(ConnectionConfiguration::class)->apply();
        $events = app(RemoteProviderEvents::class);
        $fingerprint = $events->fingerprint('ha');
        $this->assertTrue($events->accept('ha', $this->state, $fingerprint));
        $this->assertTrue($events->accept('ha', $this->state, $fingerprint));
        $this->assertSame(1, Queue::connection()->size('integrations'));
        $task = new StoreRemoteSensorEvent('ha', $this->link->id, $this->link->version, $fingerprint, $this->state, now()->toISOString());
        $lock = Cache::lock($task->middleware()[0]->getLockKey($task), 90);
        $this->assertTrue($lock->get());
        $this->work();
        $this->assertDatabaseCount('measurements', 0);
        $lock->release();
        $this->travel(6)->seconds();
        $this->work();
        $this->assertDatabaseCount('measurements', 1);
        $this->assertNull($this->link->fresh()->last_attempt_at);
        Http::assertNothingSent();
        // An event missed during disconnect is recovered by the normal periodic poll.
        $this->state = [...$this->state, 'state' => '22', 'last_reported' => now()->toISOString()];
        $this->pollResponses(200);
        $this->assertSame(1, app(RemoteSensorCollection::class)->batch('ha'));
        $this->work();
        $this->assertDatabaseCount('measurements', 2);
        Http::assertSentCount(1);
    }

    public static function revoked(): array
    {
        return array_combine(['permissions', 'scope', 'session', 'disabled', 'removed', 'version'], array_map(fn ($value) => [$value], ['permissions', 'scope', 'session', 'disabled', 'removed', 'version']));
    }

    #[DataProvider('revoked')]
    public function test_pending_work_rechecks_actor_and_link_before_contacting_the_provider(string $change): void
    {
        if ($change === 'scope') {
            $this->admin->forceFill(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('observer')->id], 'permission_grants' => ['integrations.manage']])->save();
        }
        $this->actingAs($this->admin)->postJson('/api/v1/integrations/home-assistant/links/'.$this->link->id.'/collect')->assertAccepted();
        match ($change) {
            'permissions' => $this->admin->forceFill(['permission_role_ids' => []])->save(),
            'scope' => $this->admin->forceFill(['location_access' => [['type' => 'site', 'id' => Site::create(['name' => 'Other'])->id]]])->save(),
            'session' => $this->admin->increment('session_version'),
            'disabled' => $this->link->update(['is_enabled' => false]),
            'removed' => $this->link->delete(),
            'version' => $this->link->increment('version'),
        };
        app(LocationAccess::class)->clear();
        $this->work();
        Http::assertNothingSent();
        $this->assertDatabaseCount('measurements', 0);
        $this->assertSame(0, Queue::connection()->size('integrations'), Artisan::output());
    }

    public function test_revocation_during_network_io_prevents_the_sample_from_being_committed(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/integrations/home-assistant/links/'.$this->link->id.'/collect')->assertAccepted();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://ha.example.test/api/states/*' => function () {
            User::whereKey($this->admin->id)->increment('session_version');

            return Http::response($this->state);
        }]);
        $this->work();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('measurements', 0);
        $this->assertSame(0, Queue::connection()->size('integrations'));
    }

    public function test_expired_work_is_discarded_without_provider_io(): void
    {
        app(RemoteSensorCollection::class)->enqueue(new HomeAssistantClient, $this->link);
        $this->travel(601)->seconds();
        $this->work();
        Http::assertNothingSent();
        $this->assertDatabaseCount('measurements', 0);
        $this->assertSame(0, Queue::connection()->size('integrations'));
    }

    public function test_overlap_mutex_releases_work_without_a_second_provider_request(): void
    {
        app(RemoteSensorCollection::class)->enqueue(new HomeAssistantClient, $this->link);
        $task = new CollectRemoteSensor('ha', $this->link->id, $this->link->version, now()->toISOString());
        $lock = Cache::lock($task->middleware()[0]->getLockKey($task), 90);
        $this->assertTrue($lock->get());
        $this->work();
        Http::assertNothingSent();
        $this->assertSame(1, Queue::connection()->size('integrations'));
        $lock->release();
        $this->travel(16)->seconds();
        $this->work();
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_dispatch_is_bounded_and_does_not_contact_a_provider(): void
    {
        Queue::fake();
        $sensor = $this->link->sensor->getRawOriginal();
        for ($i = 0; $i < 120; $i++) {
            $sensorId = (string) Str::uuid();
            DB::table('sensors')->insert([...$sensor, 'id' => $sensorId, 'channel_key' => 'channel_'.$i]);
            HomeAssistantLink::create(['sensor_id' => $sensorId, 'instance_key' => $this->link->instance_key, 'entity_id' => 'sensor.channel_'.$i]);
        }
        $this->artisan('measurements:collect')->assertSuccessful();
        Queue::assertPushed(CollectRemoteSensor::class, 100);
        Http::assertNothingSent();
    }
}
