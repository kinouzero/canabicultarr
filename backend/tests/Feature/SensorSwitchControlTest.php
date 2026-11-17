<?php

namespace Tests\Feature;

use App\Jobs\CollectRemoteDevice;
use App\Models\Asset;
use App\Models\DeviceCommand;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\User;
use App\Services\HomeAssistantClient;
use App\Services\HomebridgeClient;
use App\Services\RemoteSensorCollection;
use App\Services\RemoteSensorCollector;
use App\Services\SensorSwitchControl;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SensorSwitchControlTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Sensor $sensor;

    private HomebridgeLink $link;

    private bool $on = false;

    private bool $writable = true;

    private bool $available = true;

    private int $iid = 10;

    private bool $failWrite = false;

    private string $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['homebridge.url' => 'https://hb.example.test']);
        $this->configureHomebridge('https://hb.example.test', 'fictional-hb-token');
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($this->admin);
        $this->service = str_repeat('a', 64);
        $asset = Asset::create(['name' => 'Fictional switch', 'category' => 'fan']);
        $type = MeasurementType::create(['name' => 'Switch', 'code' => 'switch_fixture', 'nature' => 'binary']);
        $this->sensor = new Sensor(['name' => 'Switch', 'channel_key' => 'switch', 'is_enabled' => true]);
        $this->sensor->asset_id = $asset->id;
        $this->sensor->measurement_type_id = $type->id;
        $this->sensor->save();
        $this->link = HomebridgeLink::create(['sensor_id' => $this->sensor->id, 'entity_id' => $this->service.':10',
            'instance_key' => app(HomebridgeClient::class)->instanceKey(), 'control_entity' => $this->service.':On']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($request->method() === 'PUT') {
                if ($this->failWrite) {
                    return Http::response([], 503);
                }
                $this->assertSame('On', $request['characteristicType']);
                $this->on = (bool) $request['value'];

                return Http::response([]);
            }

            return Http::response(['uniqueId' => $this->service, 'serviceName' => 'Fictional switch', 'type' => 'Fan', 'serviceCharacteristics' => [
                ['iid' => $this->iid, 'type' => 'On', 'format' => 'bool', 'canRead' => true, 'canWrite' => $this->writable, 'value' => $this->available ? $this->on : null],
                ['iid' => 11, 'type' => 'StatusActive', 'format' => 'bool', 'canRead' => true, 'canWrite' => false, 'value' => true],
            ]]);
        });
    }

    public function test_homebridge_collection_groups_characteristics_and_rechecks_versions(): void
    {
        $second = $this->sensor->replicate();
        $second->channel_key = 'status';
        $second->save();
        $link = HomebridgeLink::create(['sensor_id' => $second->id, 'entity_id' => $this->service.':11', 'instance_key' => $this->link->instance_key]);
        $job = new CollectRemoteDevice($this->service, [$this->link->id => $this->link->version, $link->id => $link->version], now()->toISOString(), $link->instance_key);
        $job->handle(app(RemoteSensorCollection::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('measurements', 2);
        $this->assertNotNull($link->fresh()->last_success_at);
        $this->assertNotNull($this->link->fresh()->last_success_at);
        $this->travel(61)->seconds();
        $this->link->increment('version');
        $link->update(['is_enabled' => false]);
        $job->handle(app(RemoteSensorCollection::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('measurements', 2);
    }

    public function test_scheduled_homebridge_dispatch_is_one_job_per_service_without_network_io(): void
    {
        Queue::fake();
        $second = $this->sensor->replicate();
        $second->channel_key = 'status';
        $second->save();
        HomebridgeLink::create(['sensor_id' => $second->id, 'entity_id' => $this->service.':11', 'instance_key' => $this->link->instance_key]);
        $this->assertSame(2, app(RemoteSensorCollection::class)->batch('hb'));
        Queue::assertPushed(CollectRemoteDevice::class, fn ($job) => $job->queue === 'integrations' && count($job->versions) === 2);
        Queue::assertPushed(CollectRemoteDevice::class, 1);
        Http::assertNothingSent();
    }

    public function test_grouped_collection_rejects_expired_replaced_or_paused_work_before_io(): void
    {
        $versions = [$this->link->id => $this->link->version];
        $collection = app(RemoteSensorCollection::class);
        (new CollectRemoteDevice($this->service, $versions, now()->subMinutes(11)->toISOString(), $this->link->instance_key))->handle($collection);
        (new CollectRemoteDevice($this->service, $versions, now()->toISOString(), 'replaced-instance'))->handle($collection);
        config(['homebridge.sync_enabled' => false]);
        (new CollectRemoteDevice($this->service, $versions, now()->toISOString(), $this->link->instance_key))->handle($collection);
        Http::assertNothingSent();
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_grouped_collection_failure_diagnostics_do_not_modify_a_new_link_revision(): void
    {
        $job = new CollectRemoteDevice($this->service, [$this->link->id => $this->link->version], now()->toISOString(), $this->link->instance_key);
        $job->failed();
        $this->assertSame('measurements.errors.providerUnavailable', $this->link->fresh()->last_error);
        $this->link->update(['version' => $this->link->version + 1, 'last_error' => null, 'last_attempt_at' => null]);
        $job->failed();
        $this->assertNull($this->link->fresh()->last_error);
        $this->assertNull($this->link->fresh()->last_attempt_at);
        Http::assertNothingSent();
    }

    private function url(): string
    {
        return '/api/v1/sensors/'.$this->sensor->id.'/switch';
    }

    public function test_switch_reads_live_state_and_reuses_audited_idempotent_device_commands(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.isOn', false);
        $request = ['is_on' => true, 'request_id' => (string) Str::uuid()];
        $this->postJson($this->url(), $request)->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.isOn', true);
        $beforeReplay = Http::recorded()->count();
        $this->postJson($this->url(), $request)->assertOk();
        $this->assertCount($beforeReplay, Http::recorded());
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.isOn', true);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'PUT'));
        $this->assertSame(1, DeviceCommand::count());
        $this->assertSame($this->sensor->id, DeviceCommand::first()->control['sensor_id']);
        $this->postJson($this->url(), ['is_on' => false, 'request_id' => $request['request_id']])->assertConflict();
        $this->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.isOn', true);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'PUT'));
    }

    public function test_readonly_changed_characteristics_and_pumps_cannot_bypass_control_rules(): void
    {
        $this->writable = false;
        $this->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->writable = true;
        $this->iid = 12;
        $this->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->iid = 10;
        $this->available = false;
        $this->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->available = true;
        $this->sensor->device->update(['category' => 'pump']);
        $this->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
        $this->assertSame(0, DeviceCommand::count());
    }

    public function test_permissions_disabled_channels_and_changed_instances_fail_before_provider_io(): void
    {
        $reader = User::factory()->create();
        $this->actingAs($reader)->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($this->admin);
        $this->sensor->update(['is_enabled' => false]);
        $this->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->sensor->update(['is_enabled' => true]);
        $this->link->update(['instance_key' => 'different-instance']);
        $this->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_failed_writes_are_uncertain_and_cannot_be_automatically_replayed(): void
    {
        $this->failWrite = true;
        $input = ['is_on' => true, 'request_id' => (string) Str::uuid()];
        $this->postJson($this->url(), $input)->assertStatus(502);
        $this->postJson($this->url(), $input)->assertConflict();
        $this->assertSame('uncertain', DeviceCommand::first()->status);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'PUT'));
    }

    public function test_home_assistant_switch_domains_use_the_same_control_path_and_ignore_spoofed_metadata(): void
    {
        config(['home_assistant.url' => 'https://ha.example.test', 'home_assistant.token' => 'fictional-ha-token']);
        $this->link->delete();
        $client = app(HomeAssistantClient::class);
        HomeAssistantLink::create(['sensor_id' => $this->sensor->id, 'entity_id' => 'fan.fixture', 'control_entity' => 'fan.fixture', 'instance_key' => $client->instanceKey()]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                $this->assertSame('fan.fixture', $request['entity_id']);
                $this->on = str_ends_with($request->url(), '/turn_on');

                return Http::response([]);
            }

            return Http::response(['entity_id' => 'fan.fixture', 'state' => $this->on ? 'on' : 'off', 'attributes' => []]);
        });
        $this->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.isOn', true);
        $state = $client->normalizeState(['entity_id' => 'binary_sensor.readonly', 'state' => 'on', 'attributes' => ['control_entity' => 'switch.spoofed']]);
        $this->assertNull($client->controlEntity($state));
    }

    public function test_geographic_restrictions_prevent_switch_commands_without_provider_io(): void
    {
        $site = Site::create(['name' => 'Hardware site']);
        $other = Site::create(['name' => 'Restricted site']);
        $this->placeAsset($this->sensor->device, ['site_id' => $site->id]);
        $operator = User::factory()->create(['permission_grants' => ['assets.manage'], 'location_access' => [['type' => 'site', 'id' => $other->id]]]);
        $this->actingAs($operator)->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_provider_preflight_runs_outside_command_transactions_and_revalidates_binding_changes(): void
    {
        $baseline = DB::transactionLevel();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($baseline) {
            $this->assertSame($baseline, DB::transactionLevel());
            $this->assertSame('GET', $request->method());
            $this->link->update(['version' => 2]);

            return Http::response(['uniqueId' => $this->service, 'serviceCharacteristics' => [
                ['iid' => 10, 'type' => 'On', 'format' => 'bool', 'canRead' => true, 'canWrite' => true, 'value' => false],
            ]]);
        });
        $this->postJson($this->url(), ['is_on' => true, 'request_id' => (string) Str::uuid()])->assertConflict()->assertJsonPath('error.code', 'assets.power.changed');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('device_commands', 0);
    }

    private function liveUrl(array $ids): string
    {
        return '/api/v1/assets/'.$this->sensor->asset_id.'/live-readings?'.http_build_query(['sensor_ids' => $ids]);
    }

    public function test_live_device_readings_group_homebridge_channels_without_writing_history(): void
    {
        $readonly = $this->sensor->replicate();
        $readonly->id = (string) Str::uuid();
        $readonly->channel_key = 'status';
        $readonly->save();
        HomebridgeLink::create(['sensor_id' => $readonly->id, 'entity_id' => $this->service.':11', 'instance_key' => $this->link->instance_key]);
        $response = $this->getJson($this->liveUrl([$this->sensor->id, $readonly->id]))->assertOk()->assertJsonCount(2, 'data');
        $rows = collect($response->json('data'))->keyBy('sensorId');
        $this->assertSame('0.000000000', $rows[$this->sensor->id]['value']);
        $this->assertSame('1.000000000', $rows[$readonly->id]['value']);
        $this->assertFalse($rows[$this->sensor->id]['isStale']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('measurements', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_live_device_readings_are_bounded_scoped_and_skip_disabled_or_foreign_sources(): void
    {
        $this->getJson($this->liveUrl(array_fill(0, 11, (string) Str::uuid())))->assertUnprocessable();
        $this->getJson($this->liveUrl([(string) Str::uuid()]))->assertOk()->assertJsonCount(0, 'data');
        $this->link->update(['is_enabled' => false]);
        $this->getJson($this->liveUrl([$this->sensor->id]))->assertOk()->assertJsonCount(0, 'data');
        $this->link->update(['is_enabled' => true, 'instance_key' => 'another-instance']);
        $this->getJson($this->liveUrl([$this->sensor->id]))->assertOk()->assertJsonCount(0, 'data');
        $site = Site::create(['name' => 'Fictional live site']);
        $other = Site::create(['name' => 'Different live site']);
        $this->placeAsset($this->sensor->device, ['site_id' => $site->id]);
        $operator = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $other->id]]]);
        $this->actingAs($operator)->getJson($this->liveUrl([$this->sensor->id]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_live_device_readings_use_actual_unavailable_states_and_home_assistant_values(): void
    {
        $this->available = false;
        $this->getJson($this->liveUrl([$this->sensor->id]))->assertOk()->assertJsonPath('data.0.value', null)->assertJsonPath('data.0.isStale', true);
        $this->link->update(['is_enabled' => false]);
        config(['home_assistant.url' => 'https://ha.example.test', 'home_assistant.token' => 'fictional-ha-token']);
        HomeAssistantLink::create(['sensor_id' => $this->sensor->id, 'entity_id' => 'switch.fixture', 'instance_key' => app(HomeAssistantClient::class)->instanceKey()]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $remoteState = 'on';
        Http::fake(['https://ha.example.test/api/states/switch.fixture' => function () use (&$remoteState) {
            return Http::response(['entity_id' => 'switch.fixture', 'state' => $remoteState, 'attributes' => [], 'last_updated' => now()->toISOString()]);
        }]);
        $this->getJson($this->liveUrl([$this->sensor->id]))->assertOk()->assertJsonPath('data.0.value', '1.000000000');
        Http::assertSentCount(1);
        $remoteState = '2';
        $this->getJson($this->liveUrl([$this->sensor->id]))->assertOk()->assertJsonPath('data.0.value', null)->assertJsonPath('data.0.isStale', true);
    }

    public function test_live_and_canonical_metadata_share_a_revision_that_changes_with_the_binding(): void
    {
        $service = app(SensorSwitchControl::class);
        $sensors = $this->sensor->newCollection([$this->sensor->load('device', 'measurementType')]);
        $revision = $service->metadata($sensors)['sourceRevisions'][$this->sensor->id];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $revision);
        Http::assertNothingSent();
        $this->getJson($this->liveUrl([$this->sensor->id]))->assertOk()->assertJsonPath('data.0.sourceRevision', $revision);
        $this->link->update(['version' => 2]);
        $this->assertNotSame($revision, $service->metadata($sensors)['sourceRevisions'][$this->sensor->id]);
        $this->link->update(['is_enabled' => false]);
        $this->assertNull($service->metadata($sensors)['sourceRevisions'][$this->sensor->id]);
    }

    public function test_capabilities_are_specific_to_writable_boolean_characteristics(): void
    {
        $client = app(HomebridgeClient::class);
        $writable = $client->state($this->link->entity_id);
        $readonly = $client->state($this->service.':11');
        $this->assertSame($this->service.':On', $client->controlEntity($writable));
        $this->assertNull($client->controlEntity($readonly));
        $this->link->update(['control_entity' => null]);
        (new RemoteSensorCollector($client))->collect($this->link, $this->admin);
        $this->assertSame($this->service.':On', $this->link->fresh()->control_entity);
        $description = app(SensorSwitchControl::class)->describe($this->sensor->newCollection([$this->sensor->load('device', 'measurementType')]));
        $this->assertTrue($description[$this->sensor->id]['canControl']);
    }
}
