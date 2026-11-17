<?php

namespace Tests\Feature;

use App\Exceptions\MeasurementException;
use App\Jobs\StoreRemoteSensorEvent;
use App\Models\ConnectionSetting;
use App\Models\HomeAssistantLink;
use App\Models\Measurement;
use App\Models\Site;
use App\Models\User;
use App\Services\HomeAssistantEventStream;
use App\Services\RemoteEventReceiver;
use App\Services\RemoteProviderEvents;
use App\Services\RemoteSensorCollection;
use App\Services\RemoteSensorImport;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderEventsTest extends TestCase
{
    use RefreshDatabase;

    private HomeAssistantLink $link;

    private array $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['home_assistant.url' => 'https://ha.example.test', 'home_assistant.token' => 'fictional-token', 'home_assistant.sync_enabled' => true, 'home_assistant.sync_interval_seconds' => 300, 'home_assistant.events_enabled' => true]);
        Http::preventStrayRequests();
        $this->state = ['entity_id' => 'sensor.temperature', 'state' => '21.5', 'attributes' => ['friendly_name' => 'Probe', 'unit_of_measurement' => '°C', 'device_class' => 'temperature', 'private' => 'discard-this-metadata'], 'last_reported' => now()->subSecond()->toISOString()];
        Http::fake(['https://ha.example.test/api/states' => Http::response([$this->state]), 'https://ha.example.test/api/template' => Http::response([['entity_id' => 'sensor.temperature', 'device_id' => 'probe', 'name' => 'Probe']])]);
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        app(RemoteSensorImport::class)->import($admin, ['sensor.temperature']);
        $this->link = HomeAssistantLink::sole();
        Queue::fake();
    }

    private function enqueue(array $state): StoreRemoteSensorEvent
    {
        $events = app(RemoteProviderEvents::class);
        $this->assertTrue($events->accept('ha', $state, $events->fingerprint('ha')));
        $job = Queue::pushed(StoreRemoteSensorEvent::class)->last();
        $this->assertStringNotContainsString('discard-this-metadata', serialize($job));
        $this->assertStringNotContainsString('fictional-token', serialize($job));

        return $job;
    }

    public function test_events_are_idempotent_and_do_not_postpone_periodic_reconciliation(): void
    {
        $this->link->update(['last_attempt_at' => now()->subSeconds(299), 'last_success_at' => now()->subSeconds(299)]);
        $attempt = $this->link->fresh()->last_attempt_at->toISOString();
        $job = $this->enqueue($this->state);
        $requests = count(Http::recorded());
        $this->travel(1)->seconds();
        $job->handle(app(RemoteProviderEvents::class), app(RemoteSensorCollection::class));
        $job->handle(app(RemoteProviderEvents::class), app(RemoteSensorCollection::class));
        $this->assertCount($requests, Http::recorded());
        $this->assertDatabaseCount('measurements', 1);
        $sample = Measurement::sole();
        $this->assertSame('21.500000000', $sample->value);
        $this->assertSame('home_assistant', $sample->provenance_snapshot['source']['kind']);
        $this->assertSame($job->receivedAt, $sample->received_at->toISOString());
        $this->assertSame($attempt, $this->link->fresh()->last_attempt_at->toISOString());
        $this->assertSame(1, app(RemoteSensorCollection::class)->batch('ha'));
        $conflict = $this->enqueue([...$this->state, 'state' => '23']);
        $conflict->handle(app(RemoteProviderEvents::class), app(RemoteSensorCollection::class));
        $this->assertDatabaseCount('measurements', 1);
        $this->assertSame('21.500000000', $sample->fresh()->value);
    }

    public function test_delayed_event_keeps_the_original_hardware_location(): void
    {
        $site = Site::create(['name' => 'Original']);
        $asset = $this->link->sensor->device;
        $this->placeAsset($asset, ['site_id' => $site->id]);
        $job = $this->enqueue([...$this->state, 'last_reported' => now()->toISOString()]);
        $this->travel(60)->seconds();
        $other = Site::create(['name' => 'Destination']);
        $this->placeAsset($asset, ['site_id' => $other->id]);
        $job->handle(app(RemoteProviderEvents::class), app(RemoteSensorCollection::class));
        $this->assertSame($site->id, Measurement::sole()->location_snapshot['site']['id']);
        $this->assertSame($job->receivedAt, Measurement::sole()->received_at->toISOString());
    }

    public static function changes(): array
    {
        return [['credentials'], ['paused'], ['eventsDisabled'], ['linkDisabled'], ['version'], ['expired']];
    }

    #[DataProvider('changes')]
    public function test_pending_event_rechecks_current_configuration_and_source(string $change): void
    {
        $job = $this->enqueue($this->state);
        match ($change) {
            'credentials' => config(['home_assistant.token' => 'different-fictional-token']),
            'paused' => config(['home_assistant.sync_enabled' => false]),
            'eventsDisabled' => config(['home_assistant.events_enabled' => false]),
            'linkDisabled' => $this->link->update(['is_enabled' => false]),
            'version' => $this->link->increment('version'),
            'expired' => $this->travel(601)->seconds(),
        };
        $job->handle(app(RemoteProviderEvents::class), app(RemoteSensorCollection::class));
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_unmapped_disabled_invalid_and_overloaded_admission_does_not_queue(): void
    {
        $events = app(RemoteProviderEvents::class);
        $fingerprint = $events->fingerprint('ha');
        foreach ([['entity_id' => 'sensor.unknown'], ['state' => ['not-a-scalar']], ['attributes' => ['unit_of_measurement' => str_repeat('x', 25)]], ['last_reported' => str_repeat('x', 41)]] as $override) {
            $this->assertFalse($events->accept('ha', [...$this->state, ...$override], $fingerprint));
        }
        $this->link->update(['is_enabled' => false]);
        $this->assertFalse($events->accept('ha', $this->state, $fingerprint));
        Queue::assertNothingPushed();
        $this->link->update(['is_enabled' => true]);
        $factory = Mockery::mock(Factory::class);
        $connection = Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $connection->shouldReceive('size')->with('integrations')->andReturn(2000);
        $factory->shouldReceive('connection')->andReturn($connection);
        Queue::swap($factory);
        $this->assertFalse($events->accept('ha', $this->state, $fingerprint));
    }

    public function test_reconnect_backoff_is_bounded_and_credential_change_resets_it(): void
    {
        $row = $this->configureReceiver();
        $stream = Mockery::mock(HomeAssistantEventStream::class);
        $stream->shouldReceive('receive')->twice()->andThrow(new \RuntimeException('Private transport detail'));
        $this->app->instance(HomeAssistantEventStream::class, $stream);
        $receiver = app(RemoteEventReceiver::class);
        $receiver->receive('ha', 1);
        $this->assertSame('disconnected', Cache::get('remote-events:ha:status')['state']);
        $retry = Cache::get('remote-events:ha:retry');
        $this->assertSame(1, $retry['failures']);
        $this->assertLessThanOrEqual(300, $retry['after'] - time());
        $receiver->receive('ha', 1);
        $row->update(['values' => [...$row->values, 'HOME_ASSISTANT_TOKEN' => 'changed-fictional-token']]);
        $receiver->receive('ha', 1);
        $this->assertNotSame($retry['fingerprint'], Cache::get('remote-events:ha:retry')['fingerprint']);
        $this->assertSame(1, Cache::get('remote-events:ha:retry')['failures']);
    }

    private function configureReceiver(): ConnectionSetting
    {
        foreach (array_keys(config('connection_settings.home_assistant')) as $field) {
            config(['connection_settings.home_assistant.'.$field.'.locked' => false]);
        }
        $row = ConnectionSetting::findOrFail('home_assistant');
        $row->update(['values' => ['HOME_ASSISTANT_URL' => 'https://ha.example.test', 'HOME_ASSISTANT_TOKEN' => 'fictional-token', 'HOME_ASSISTANT_EVENTS_ENABLED' => true]]);

        return $row;
    }

    public function test_event_receiver_stops_after_configuration_is_disabled_and_releases_its_lock(): void
    {
        $this->configureReceiver();
        $stream = Mockery::mock(HomeAssistantEventStream::class);
        $stream->shouldReceive('receive')->once()->andReturnUsing(function ($accept, $continue) {
            $this->assertTrue($continue());
            $accept($this->state);
            config(['home_assistant.sync_enabled' => false]);
            $this->assertFalse($continue());
        });
        $this->app->instance(HomeAssistantEventStream::class, $stream);
        app(RemoteEventReceiver::class)->receive('ha', 1);
        Queue::assertPushed(StoreRemoteSensorEvent::class, 1);
        $this->assertSame('idle', Cache::get('remote-events:ha:status')['state']);
        $this->assertNull(Cache::get('remote-events:ha:retry'));
        $lock = Cache::lock('remote-events:ha:receiver', 30);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public static function invalidReceiverUrls(): array
    {
        return [['file:///etc/passwd'], ['https://user:password@ha.example.test'], ['https://ha.example.test?token=private'], ['https://ha.example.test#fragment']];
    }

    #[DataProvider('invalidReceiverUrls')]
    public function test_receiver_urls_reject_non_http_destinations_credentials_and_query_tokens(string $url): void
    {
        config(['home_assistant.url' => $url]);
        $this->expectException(MeasurementException::class);
        app(RemoteEventReceiver::class)->url('ha');
    }
}
