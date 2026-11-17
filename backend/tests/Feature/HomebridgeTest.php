<?php

namespace Tests\Feature;

use App\Exceptions\MeasurementException;
use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\HomebridgeLink;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Site;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\HomebridgeClient;
use App\Services\RemoteSensorCollector;
use App\Services\RemoteSensorImport;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HomebridgeTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/v1/integrations/homebridge';

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Cache::flush();
        config(['homebridge.url' => 'https://hb.example.test']);
        $this->configureHomebridge('https://hb.example.test', 'fictional-hb-token');
        Http::preventStrayRequests();
    }

    public function test_credentials_obtain_and_reuse_an_encrypted_session_until_expiry(): void
    {
        config(['homebridge.username' => 'integration', 'homebridge.password' => 'fictional-password']);
        Http::fake([
            '*/auth/login' => fn () => Http::response(['access_token' => 'issued-token', 'expires_in' => 60]),
            '*/accessories' => fn () => Http::response([]),
        ]);
        (new HomebridgeClient)->check();
        (new HomebridgeClient)->check();
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://hb.example.test/api/auth/login'
            && $request->method() === 'POST' && ! $request->hasHeader('Authorization')
            && $request['username'] === 'integration' && $request['password'] === 'fictional-password');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer issued-token'));
        $key = 'homebridge:session:'.hash_hmac('sha256', json_encode([
            'https://hb.example.test', 'integration', 'fictional-password',
        ]), config('app.key'));
        $this->assertStringNotContainsString('issued-token', json_encode(Cache::get($key)));
        $this->travel(61)->seconds();
        (new HomebridgeClient)->check();
        Http::assertSentCount(5);
    }

    public function test_a_rejected_session_is_renewed_once_but_permission_denial_is_not(): void
    {
        config(['homebridge.username' => 'integration', 'homebridge.password' => 'fictional-password']);
        Http::fake([
            '*/auth/login' => Http::sequence()->push(['access_token' => 'first', 'expires_in' => 3600])->push(['access_token' => 'second', 'expires_in' => 3600]),
            '*/accessories' => Http::sequence()->push([], 401)->push([])->push([], 403),
        ]);
        (new HomebridgeClient)->check();
        Http::assertSentCount(4);
        try {
            (new HomebridgeClient)->check();
            $this->fail('Permission denial must be reported.');
        } catch (MeasurementException $exception) {
            $this->assertSame(403, $exception->providerStatus);
        }
        Http::assertSentCount(5);
    }

    public function test_failed_logins_are_throttled_and_changing_credentials_retries_immediately(): void
    {
        config(['homebridge.username' => 'integration', 'homebridge.password' => 'wrong-password']);
        Http::fake(['*/auth/login' => Http::response(['message' => 'private provider detail'], 403)]);
        foreach (range(1, 2) as $_) {
            try {
                (new HomebridgeClient)->check();
                $this->fail('Invalid credentials must be rejected.');
            } catch (MeasurementException $exception) {
                $this->assertSame('measurements.errors.accessDenied', $exception->errorCode);
            }
        }
        Http::assertSentCount(1);
        config(['homebridge.password' => 'changed-password']);
        try {
            (new HomebridgeClient)->check();
        } catch (MeasurementException) {
            Http::assertSentCount(2);
        }
    }

    public function test_two_factor_authentication_and_malformed_sessions_fail_without_requesting_accessories(): void
    {
        config(['homebridge.username' => 'integration', 'homebridge.password' => 'fictional-password']);
        Http::fake(['*/auth/login' => Http::sequence()->push([], 412)->push(['access_token' => 'bad token', 'expires_in' => 3600])]);
        foreach (['measurements.errors.twoFactorRequired', 'measurements.errors.invalidResponse'] as $code) {
            Cache::flush();
            try {
                (new HomebridgeClient)->check();
                $this->fail('Unsupported sessions must fail.');
            } catch (MeasurementException $exception) {
                $this->assertSame($code, $exception->errorCode);
            }
        }
        Http::assertSentCount(2);
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function accessory(string $id = 'a', int $aid = 1, array $extra = []): array
    {
        return [...['uniqueId' => str_repeat($id, 64), 'aid' => $aid, 'type' => 'TemperatureSensor', 'serviceName' => 'Fictional Greenhouse', 'instance' => ['username' => 'AA:BB:CC:DD:EE:FF'], 'accessoryInformation' => ['Name' => 'Fictional Probe', 'Manufacturer' => 'Example', 'Model' => 'Probe'], 'serviceCharacteristics' => [
            ['iid' => 10, 'type' => 'CurrentTemperature', 'format' => 'float', 'unit' => 'celsius', 'value' => 21.5, 'canRead' => true],
            ['iid' => 11, 'type' => 'CurrentRelativeHumidity', 'format' => 'float', 'unit' => 'percentage', 'value' => 55, 'canRead' => true],
            ['iid' => 12, 'type' => 'Name', 'format' => 'string', 'value' => 'Not a measurement', 'canRead' => true],
            ['iid' => 13, 'type' => 'Identify', 'format' => 'bool', 'value' => false, 'canRead' => false],
        ]], ...$extra];
    }

    private function fake(array $rows, ?array $refreshed = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://hb.example.test/api/accessories' => fn () => Http::response($rows), 'https://hb.example.test/api/accessories/*' => fn () => Http::response($refreshed ?? $rows[0])]);
    }

    private function workQueuedPolls(): void
    {
        while ($job = Queue::connection()->pop('integrations')) {
            $job->fire();
            $job->delete();
        }
    }

    private function imported(User $actor): HomebridgeLink
    {
        $this->fake([$this->accessory()]);
        (new RemoteSensorImport(new HomebridgeClient))->import($actor, [str_repeat('a', 64).':10']);

        return HomebridgeLink::firstOrFail();
    }

    public function test_imported_accessory_can_be_removed_and_imported_again_without_duplicate_channels(): void
    {
        $admin = $this->admin();
        $link = $this->imported($admin);
        $this->actingAs($admin)->deleteJson(self::ROOT.'/links/'.$link->id, ['version' => 1])->assertNoContent();
        $this->assertSoftDeleted('homebridge_links', ['id' => $link->id]);
        $this->getJson(self::ROOT.'/links')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::ROOT.'/import', ['device_ids' => ['device:'.hash('sha256', 'AA:BB:CC:DD:EE:FF:1')]])->assertCreated()->assertJsonPath('data.sensorsCreated', 1);
        $this->assertSame($link->sensor_id, HomebridgeLink::findOrFail($link->id)->sensor_id);
        $this->assertSame(3, HomebridgeLink::findOrFail($link->id)->version);
        $this->assertDatabaseCount('sensors', 2);
    }

    public function test_access_requires_integration_permission_and_discovery_requires_global_access(): void
    {
        $this->getJson(self::ROOT)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(self::ROOT)->assertForbidden();
        $restricted = User::factory()->create(['permission_grants' => ['integrations.manage'], 'location_access' => []]);
        $this->actingAs($restricted)->getJson(self::ROOT)->assertOk();
        $this->getJson(self::ROOT.'/entities')->assertForbidden();
        $this->postJson(self::ROOT.'/check')->assertForbidden();
        $this->postJson(self::ROOT.'/import', ['device_ids' => ['device:'.hash('sha256', 'AA:BB:CC:DD:EE:FF:1')]])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_discovery_paginates_and_filters_readable_numeric_channels_without_exposing_provider_data(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = $this->accessory(dechex($i), $i + 1);
        }
        $this->fake($rows);
        $this->actingAs($this->admin())->getJson(self::ROOT)->assertJsonPath('data.configured', true)->assertJsonPath('data.sync.enabled', true)->assertJsonPath('data.sync.intervalSeconds', 60);
        $this->getJson(self::ROOT.'/entities')->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('meta.total', 12)->assertJsonMissingPath('data.0.state')->assertJsonMissingPath('data.0.instance');
        $this->getJson(self::ROOT.'/entities?per_page=10&page=2')->assertJsonCount(2, 'data');
        $this->getJson(self::ROOT.'/entities?search=humidity')->assertJsonPath('meta.total', 12);
        $this->assertDatabaseCount('assets', 0);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer fictional-hb-token') && $r->method() === 'GET');
    }

    public function test_import_groups_services_as_hardware_is_idempotent_and_does_not_mark_air_humidity_as_soil(): void
    {
        $this->fake([$this->accessory(), $this->accessory('b')]);
        $member = User::factory()->create(['permission_grants' => ['integrations.manage']]);
        $ids = ['device:'.hash('sha256', 'AA:BB:CC:DD:EE:FF:1')];
        $this->actingAs($member)->postJson(self::ROOT.'/import', ['device_ids' => $ids])->assertCreated()->assertJsonPath('data.devicesCreated', 1)->assertJsonPath('data.sensorsCreated', 4);
        $this->postJson(self::ROOT.'/import', ['device_ids' => $ids])->assertCreated()->assertJsonPath('data.alreadyImported', 4)->assertJsonPath('data.devicesCreated', 0);
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('home_assistant_links', 0);
        $this->assertDatabaseCount('measurements', 0);
        $this->assertSame('air_humidity', MeasurementType::where('unit', '%')->firstOrFail()->nature);
        $this->getJson(self::ROOT.'/links')->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_collect_refreshes_the_accessory_and_stores_exact_values_poll_time_and_location(): void
    {
        $actor = $this->admin();
        $link = $this->imported($actor);
        $site = Site::create(['name' => 'Fictional Site']);
        $this->placeAsset(Asset::firstOrFail(), ['site_id' => $site->id]);
        $fresh = $this->accessory();
        $fresh['serviceCharacteristics'][0]['value'] = 23.75;
        $this->fake([$this->accessory()], $fresh);
        $this->freezeTime();
        $this->actingAs($actor)->postJson(self::ROOT.'/links/'.$link->id.'/collect')->assertAccepted()->assertJsonPath('data.outcome', 'queued');
        $this->assertDatabaseCount('measurements', 0);
        $this->workQueuedPolls();
        $sample = Measurement::firstOrFail();
        $this->assertSame('unknown', $sample->quality);
        $this->assertSame('23.750000000', $sample->value);
        $this->assertSame('°C', $sample->unit);
        $this->assertSame($link->id, $sample->homebridge_link_id);
        $this->assertSame($site->id, $sample->location_snapshot['site']['id']);
        $this->assertTrue($sample->observed_at->equalTo(now()));
        $this->postJson(self::ROOT.'/links/'.$link->id.'/collect')->assertAccepted()->assertJsonPath('data.outcome', 'queued');
        $this->workQueuedPolls();
        $this->assertDatabaseCount('measurements', 1);
        Http::assertSent(fn ($r) => $r->url() === 'https://hb.example.test/api/accessories/'.str_repeat('a', 64));
    }

    public function test_failed_characteristics_units_and_changed_instances_never_store_stale_readings(): void
    {
        $actor = $this->admin();
        $link = $this->imported($actor);
        $fresh = $this->accessory();
        $fresh['serviceCharacteristics'][0]['status'] = -70402;
        $this->fake([$fresh]);
        $collector = new RemoteSensorCollector(new HomebridgeClient);
        $this->assertSame('measurements.errors.invalidValue', $collector->collect($link, $actor)['code']);
        $fresh['serviceCharacteristics'][0]['status'] = 0;
        $fresh['serviceCharacteristics'][0]['unit'] = 'percentage';
        $this->fake([$fresh]);
        $this->assertSame('measurements.errors.unitMismatch', $collector->collect($link, $actor)['code']);
        config(['homebridge.url' => 'https://other.example.test']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame('measurements.errors.instanceChanged', $collector->collect($link, $actor)['code']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_pause_versions_retired_devices_and_geographic_scope_protect_collection(): void
    {
        $actor = $this->admin();
        $link = $this->imported($actor);
        $this->actingAs($actor)->patchJson(self::ROOT.'/links/'.$link->id, ['version' => 1, 'is_enabled' => false])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson(self::ROOT.'/links/'.$link->id, ['version' => 1, 'is_enabled' => true])->assertConflict();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->postJson(self::ROOT.'/links/'.$link->id.'/collect')->assertJsonPath('data.outcome', 'skipped');
        Http::assertNothingSent();
        $this->actingAs(User::factory()->create(['permission_grants' => ['integrations.manage'], 'location_access' => []]));
        $this->getJson(self::ROOT.'/links')->assertJsonCount(0, 'data');
        $this->postJson(self::ROOT.'/links/'.$link->id.'/collect')->assertNotFound();
        $this->patchJson(self::ROOT.'/links/'.$link->id, ['version' => 2, 'is_enabled' => true])->assertNotFound();
    }

    public function test_lost_acknowledgment_replay_keeps_the_homebridge_poll_timestamp(): void
    {
        $actor = $this->admin();
        $link = $this->imported($actor);
        $this->freezeTime();
        $this->actingAs($actor)->postJson(self::ROOT.'/links/'.$link->id.'/collect')->assertAccepted();
        $payload = DB::table('jobs')->value('payload');
        $this->workQueuedPolls();
        $observed = Measurement::sole()->observed_at;
        $this->travel(30)->seconds();
        Queue::connection()->pushRaw($payload, 'integrations');
        $this->workQueuedPolls();
        $this->assertDatabaseCount('measurements', 1);
        $this->assertTrue(Measurement::sole()->observed_at->equalTo($observed));
    }

    public function test_invalid_selections_and_provider_errors_are_sanitized_without_partial_imports(): void
    {
        $this->fake([$this->accessory()]);
        $this->actingAs($this->admin());
        $this->postJson(self::ROOT.'/import', ['device_ids' => ['../../bad']])->assertUnprocessable();
        $this->postJson(self::ROOT.'/import', ['device_ids' => ['device:'.hash('sha256', 'AA:BB:CC:DD:EE:FF:1'), 'device:missing']])->assertUnprocessable();
        $this->assertDatabaseCount('assets', 0);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['secret' => 'fictional-private-information'], 401)]);
        $this->postJson(self::ROOT.'/check')->assertStatus(503)->assertDontSee('fictional-private-information')->assertJsonPath('error.code', 'measurements.errors.accessDenied');
        config(['homebridge.url' => 'https://user:password@example.test']);
        $this->postJson(self::ROOT.'/check')->assertStatus(503)->assertJsonPath('error.code', 'measurements.errors.notConfigured');
    }

    public function test_boolean_accessory_state_and_scheduler_share_the_normal_collection_pipeline(): void
    {
        $service = $this->accessory('a', 1, ['type' => 'Outlet', 'serviceCharacteristics' => [['iid' => 10, 'type' => 'On', 'format' => 'bool', 'value' => true, 'canRead' => true]]]);
        $this->fake([$service]);
        (new RemoteSensorImport(new HomebridgeClient))->import($this->admin(), [str_repeat('a', 64).':10']);
        $this->assertDatabaseHas('assets', ['category' => 'socket']);
        $this->artisan('measurements:collect-homebridge')->assertSuccessful();
        $this->assertDatabaseCount('measurements', 0);
        $this->workQueuedPolls();
        $this->assertSame('1.000000000', Measurement::firstOrFail()->value);
        $this->getJson('/api/v1/sensors/'.HomebridgeLink::firstOrFail()->sensor_id.'/measurements')->assertUnauthorized();
    }

    public function test_homebridge_sources_appear_in_scoped_diagnostics_overview_and_reader_history(): void
    {
        $actor = $this->admin();
        $link = $this->imported($actor);
        $site = Site::create(['name' => 'Homebridge Test Site']);
        $this->placeAsset(Asset::firstOrFail(), ['site_id' => $site->id]);
        (new RemoteSensorCollector(new HomebridgeClient))->collect($link, $actor);
        $this->actingAs($actor)->getJson('/api/v1/integrations/sources?kind=homebridge')->assertOk()->assertJsonPath('summary.homebridge', 1)->assertJsonPath('data.0.kind', 'homebridge')->assertJsonPath('data.0.state', 'enabled');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.sources.homebridge.total', 1)->assertJsonPath('data.sensors.linked', 1);
        $member = User::factory()->create(['permission_grants' => ['integrations.manage'], 'location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $this->actingAs($member)->getJson(self::ROOT.'/links')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sensors/'.$link->sensor_id.'/measurements')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/integrations/sources?kind=homebridge')->assertOk()->assertJsonPath('summary.homebridge', 1);
        $this->actingAs(User::factory()->create(['permission_grants' => ['integrations.manage'], 'location_access' => []]))->getJson('/api/v1/integrations/sources?kind=homebridge')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.homebridge', 0);
    }

    public function test_polling_channel_rejects_other_sources_and_pausing_suppresses_monitoring(): void
    {
        $actor = $this->admin();
        $link = $this->imported($actor);
        config(['mqtt.enabled' => true, 'mqtt.host' => 'fictional-broker', 'mqtt.port' => 1883, 'mqtt.tls' => false]);
        $this->actingAs($actor)->postJson('/api/v1/integrations/http/sources', ['sensor_id' => $link->sensor_id, 'expires_days' => 90])->assertConflict();
        $this->postJson('/api/v1/integrations/mqtt/sources', ['sensor_id' => $link->sensor_id, 'topic' => 'fictional/probe'])->assertConflict();
        (new RemoteSensorCollector(new HomebridgeClient))->collect($link, $actor);
        $rule = AlertRule::create(['sensor_id' => $link->sensor_id, 'name' => 'Fictional Homebridge alert', 'kind' => 'threshold', 'severity' => 'warning', 'maximum' => '10', 'max_age_seconds' => 600, 'activated_at' => now()->subHour()]);
        app(AlertEvaluator::class)->evaluate($rule);
        $this->assertSame('alerting', $rule->fresh()->last_result);
        $link->update(['is_enabled' => false]);
        app(AlertEvaluator::class)->evaluate($rule);
        $this->assertSame('suppressed', $rule->fresh()->last_result);
        $this->assertSame('source_disabled', $rule->episodes()->sole()->resolution);
    }

    public function test_whole_device_import_groups_all_channels_and_preserves_customizations(): void
    {
        $pump = $this->accessory('a', 1, ['type' => 'Valve', 'serviceName' => 'Flowering pump', 'serviceCharacteristics' => [
            ['iid' => 10, 'type' => 'Active', 'format' => 'uint8', 'value' => 0, 'canRead' => true, 'canWrite' => true],
            ['iid' => 11, 'type' => 'InUse', 'format' => 'uint8', 'value' => 0, 'canRead' => true],
        ]]);
        $this->fake([$pump, $this->accessory('b', 1), $this->accessory('d', 1), $this->accessory('c', 2)]);
        $actor = $this->admin();
        $this->actingAs($actor);
        $key = 'device:'.hash('sha256', 'AA:BB:CC:DD:EE:FF:1');
        $discovered = $this->getJson(self::ROOT.'/entities?search=flowering')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $key)
            ->assertJsonPath('data.0.category', 'pump')->assertJsonPath('data.0.entityCount', 6)->assertJsonCount(5, 'data.0.entities');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('assets', 0);
        $this->postJson(self::ROOT.'/import', ['device_ids' => [$key]])->assertCreated()->assertJsonPath('data.devicesCreated', 1)->assertJsonPath('data.sensorsCreated', 6);
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('sensors', 6);
        $this->assertSame('pump', Asset::sole()->category->value);
        $active = HomebridgeLink::where('entity_id', str_repeat('a', 64).':10')->firstOrFail()->sensor;
        $this->patchJson('/api/v1/sensors/'.$active->id, ['name' => 'Custom pump state', 'is_enabled' => false, 'expected_interval_seconds' => 60, 'description' => null])->assertOk();
        $this->postJson(self::ROOT.'/import', ['device_ids' => [$key]])->assertCreated()->assertJsonPath('data.alreadyImported', 6)->assertJsonPath('data.sensorsCreated', 0);
        $this->assertFalse($active->fresh()->is_enabled);
        $this->assertSame('Custom pump state', $active->fresh()->name);
        $this->assertSame('binary', $active->measurementType->nature);
        $this->assertSame(0, $active->measurementType->decimal_places);
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_whole_device_import_rejects_stale_unknown_or_unauthorized_selections_atomically(): void
    {
        $this->fake([$this->accessory()]);
        $actor = $this->admin();
        $key = 'device:'.hash('sha256', 'AA:BB:CC:DD:EE:FF:1');
        $this->actingAs($actor)->postJson(self::ROOT.'/import', ['device_ids' => [$key, 'device:missing']])->assertUnprocessable();
        $this->assertDatabaseCount('assets', 0);
        $this->postJson(self::ROOT.'/import', ['device_ids' => [$key, $key]])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson(self::ROOT.'/import', ['device_ids' => [$key]])->assertForbidden();
        $this->assertDatabaseCount('sensors', 0);
    }
}
