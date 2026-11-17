<?php

namespace Tests\Feature;

use App\Exceptions\MeasurementException;
use App\Models\HomeAssistantLink;
use App\Models\HttpMeasurementSource;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Site;
use App\Models\User;
use App\Services\HomeAssistantClient;
use App\Services\MeasurementValue;
use App\Services\RemoteSensorCollector;
use App\Services\RemoteSensorImport;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HomeAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Cache::flush();
        config(['home_assistant.url' => 'https://ha.example.test', 'home_assistant.token' => 'test-token']);
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function state(string $entity = 'sensor.temperature', string $value = '21.5', array $extra = []): array
    {
        return ['entity_id' => $entity, 'state' => $value, 'attributes' => ['friendly_name' => 'Test Temperature', 'unit_of_measurement' => '°C', 'device_class' => 'temperature'], 'last_updated' => now()->subSeconds(20)->toISOString(), 'last_reported' => now()->subSeconds(10)->toISOString(), ...$extra];
    }

    private function fake(array $states = [], array $devices = []): void
    {
        $states = $states ?: [$this->state()];
        $devices = $devices ?: array_map(fn ($s) => ['entity_id' => $s['entity_id'], 'device_id' => 'physical-device-1', 'name' => 'Test Physical Device', 'manufacturer' => 'Test Manufacturer', 'model' => 'Model A'], $states);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://ha.example.test/api/states' => fn () => Http::response($states), 'https://ha.example.test/api/template' => fn () => Http::response(json_encode($devices)), 'https://ha.example.test/api/states/*' => fn ($request) => Http::response(collect($states)->firstWhere('entity_id', rawurldecode(basename($request->url()))) ?? $states[0]), 'https://ha.example.test/api/' => fn () => Http::response(['message' => 'API running'])]);
    }

    private function workQueuedPolls(): void
    {
        while ($job = Queue::connection()->pop('integrations')) {
            $job->fire();
            $job->delete();
        }
    }

    private function imported(User $admin): HomeAssistantLink
    {
        $this->fake();
        app(RemoteSensorImport::class)->import($admin, ['sensor.temperature']);

        return HomeAssistantLink::sole();
    }

    public function test_guests_and_members_cannot_access_integration_or_import(): void
    {
        $this->getJson('/api/v1/integrations/home-assistant')->assertUnauthorized();
        $this->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => ['device:physical-device-1']])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/integrations/home-assistant/entities')->assertForbidden();
        $this->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => ['device:physical-device-1']])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_status_and_health_never_expose_endpoint_or_token(): void
    {
        $this->fake();
        $this->actingAs($this->admin())->getJson('/api/v1/integrations/home-assistant')->assertJsonPath('data.configured', true)->assertJsonPath('data.sync.enabled', true)->assertJsonPath('data.sync.intervalSeconds', 60);
        $this->postJson('/api/v1/integrations/home-assistant/check')->assertExactJson(['data' => ['connected' => true]]);
        Http::assertSent(fn ($r) => $r->url() === 'https://ha.example.test/api/' && $r->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_discovery_is_filtered_paginated_and_excludes_nonnumeric_entities(): void
    {
        $states = [];
        for ($i = 0; $i < 22; $i++) {
            $states[] = $this->state('sensor.temperature_'.$i);
        }$states[] = $this->state('switch.light', 'on');
        $states[] = $this->state('sensor.description', 'text');
        $devices = array_map(fn ($state, $index) => ['entity_id' => $state['entity_id'], 'device_id' => 'physical-'.$index, 'name' => 'Device '.$index], $states, array_keys($states));
        $this->fake($states, $devices);
        $this->actingAs($this->admin())->getJson('/api/v1/integrations/home-assistant/entities')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23)->assertJsonMissingPath('data.0.state');
        $this->getJson('/api/v1/integrations/home-assistant/entities?page=2')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/integrations/home-assistant/entities?per_page=10&page=2')->assertJsonCount(10, 'data')->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/v1/integrations/home-assistant/entities?search=temperature_21')->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_selection_creates_real_device_sensor_type_and_mapping_without_placeholders(): void
    {
        $this->fake();
        $this->actingAs($this->admin())->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => ['device:physical-device-1'], 'name' => 'Forged device', 'unit' => 'Forged'])->assertCreated()->assertJsonPath('data.devicesCreated', 1)->assertJsonPath('data.sensorsCreated', 1)->assertJsonPath('data.typesCreated', 1);
        $this->assertDatabaseHas('assets', ['name' => 'Test Physical Device', 'manufacturer' => 'Test Manufacturer', 'model' => 'Model A']);
        $this->assertDatabaseHas('sensors', ['name' => 'Test Temperature']);
        $this->assertDatabaseCount('measurements', 0);
        $this->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => ['device:physical-device-1']])->assertCreated()->assertJsonPath('data.alreadyImported', 1)->assertJsonPath('data.devicesCreated', 0);
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('sensors', 1);
    }

    public function test_entities_from_the_same_device_reuse_device_and_measurement_type(): void
    {
        $this->fake([$this->state(), $this->state('sensor.temperature_2')]);
        $result = app(RemoteSensorImport::class)->import($this->admin(), ['sensor.temperature', 'sensor.temperature_2']);
        $this->assertSame(1, $result['devicesCreated']);
        $this->assertSame(1, $result['typesCreated']);
        $this->assertDatabaseCount('sensors', 2);
        $this->assertDatabaseCount('home_assistant_links', 2);
    }

    public function test_entity_without_registry_device_creates_a_real_logical_source_device(): void
    {
        $this->fake([$this->state()], [['entity_id' => 'sensor.temperature', 'device_id' => null, 'name' => null]]);
        app(RemoteSensorImport::class)->import($this->admin(), ['sensor.temperature']);
        $this->assertDatabaseHas('home_assistant_devices', ['source_key' => 'entity:sensor.temperature']);
        $this->assertDatabaseHas('assets', ['name' => 'Test Temperature']);
    }

    public function test_manual_hardware_categories_remain_independent_from_home_assistant(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        foreach (['fan', 'sensor'] as $category) {
            $this->postJson('/api/v1/assets', ['name' => 'Manual '.$category, 'category' => $category, 'status' => 'active'])->assertCreated()->assertJsonPath('data.category', $category)->assertJsonMissingPath('data.kind');
        }
        $this->assertDatabaseCount('assets', 2);
        $this->assertDatabaseCount('home_assistant_devices', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_and_missing_selections_do_not_create_partial_records(): void
    {
        $admin = $this->admin();
        $this->fake();
        $this->actingAs($admin)->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => ['device:physical-device-1', 'device:missing']])->assertUnprocessable();
        $this->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => ['device:physical-device-1', 'device:physical-device-1']])->assertUnprocessable();
        $this->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => ['device:bad{{id}}']])->assertUnprocessable();
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_collection_stores_report_time_exact_value_unit_and_location_snapshot(): void
    {
        $admin = $this->admin();
        $link = $this->imported($admin);
        $asset = $link->sensor->device;
        $site = Site::create(['name' => 'Recorded Site']);
        $this->placeAsset($asset, ['site_id' => $site->id]);
        $this->travel(11)->seconds();
        $state = $this->state();
        $this->fake([$state]);
        $result = app(RemoteSensorCollector::class)->collect($link, $admin);
        $this->assertSame('stored', $result['outcome']);
        $measurement = Measurement::sole();
        $this->assertSame('21.500000000', $measurement->value);
        $this->assertSame($state['last_reported'], $measurement->observed_at->toISOString());
        $this->assertSame('Recorded Site', $measurement->location_snapshot['site']['name']);
        $this->assertSame('°C', $measurement->unit);
        $site->update(['name' => 'Renamed Site']);
        $this->assertSame('Recorded Site', $measurement->fresh()->location_snapshot['site']['name']);
        $this->assertNull($link->fresh()->last_error);
    }

    public function test_provider_quality_restored_values_and_malformed_metadata_are_not_silently_certified(): void
    {
        $link = $this->imported($this->admin());
        $state = $this->state();
        $state['attributes']['restored'] = true;
        $this->fake([$state]);
        $collector = app(RemoteSensorCollector::class);
        $this->assertSame('stored', $collector->collect($link)['outcome']);
        $this->assertSame('suspect', Measurement::sole()->quality);
        $state['attributes']['restored'] = false;
        $state['attributes']['canabicultarr_quality'] = 'good';
        $this->fake([$state]);
        $this->assertSame('measurements.errors.sampleConflict', $collector->collect($link)['code']);
        $state['attributes']['canabicultarr_quality'] = 'bad';
        $this->fake([$state]);
        $this->assertSame('measurements.errors.invalidQuality', $collector->collect($link)['code']);
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_unchanged_samples_are_idempotent_and_conflicting_values_never_overwrite(): void
    {
        $link = $this->imported($this->admin());
        $state = $this->state();
        $this->fake([$state]);
        $collector = app(RemoteSensorCollector::class);
        $this->assertSame('stored', $collector->collect($link)['outcome']);
        $this->assertSame('duplicate', $collector->collect($link)['outcome']);
        $this->assertDatabaseCount('measurements', 1);
        $this->fake([[...$state, 'state' => '22']]);
        $this->assertSame('measurements.errors.sampleConflict', $collector->collect($link)['code']);
        $this->assertSame('21.500000000', Measurement::sole()->value);
    }

    public function test_unavailable_units_and_invalid_times_are_rejected_without_fake_readings(): void
    {
        $link = $this->imported($this->admin());
        $collector = app(RemoteSensorCollector::class);
        foreach ([[$this->state(value: 'unavailable'), 'invalidValue'], [$this->state(extra: ['attributes' => ['unit_of_measurement' => '°F']]), 'unitMismatch'], [$this->state(extra: ['last_reported' => 'tomorrow']), 'invalidTime'], [$this->state(extra: ['last_reported' => now()->addHour()->toISOString()]), 'invalidTime']] as [$state,$code]) {
            $this->fake([$state]);
            $this->assertSame('measurements.errors.'.$code, $collector->collect($link)['code']);
        }
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_paused_disabled_and_retired_sources_are_not_requested(): void
    {
        $link = $this->imported($this->admin());
        Http::fake();
        $link->update(['is_enabled' => false]);
        $this->assertSame('skipped', app(RemoteSensorCollector::class)->collect($link)['outcome']);
        $link->update(['is_enabled' => true]);
        $link->sensor->update(['is_enabled' => false]);
        $this->assertSame('skipped', app(RemoteSensorCollector::class)->collect($link)['outcome']);
        $link->sensor->update(['is_enabled' => true]);
        $link->sensor->device->update(['status' => 'retired']);
        $this->assertSame('skipped', app(RemoteSensorCollector::class)->collect($link)['outcome']);
        Http::assertNothingSent();
    }

    public function test_foreign_instance_is_not_contacted_for_existing_mappings(): void
    {
        $link = $this->imported($this->admin());
        Http::fake();
        config(['home_assistant.url' => 'https://other.example.test']);
        $this->assertSame('measurements.errors.instanceChanged', app(RemoteSensorCollector::class)->collect($link)['code']);
        Http::assertNothingSent();
    }

    public function test_adapter_failures_are_sanitized_and_redirects_never_forward_credentials(): void
    {
        foreach ([401, 403, 302, 500] as $status) {
            Http::fake(['*' => Http::response('secret upstream body', $status, ['Location' => 'https://other.example.test'])]);
            try {
                app(HomeAssistantClient::class)->check();
                $this->fail('Provider failure accepted');
            } catch (MeasurementException $e) {
                $this->assertStringStartsWith('measurements.errors.', $e->errorCode);
                $this->assertStringNotContainsString('secret', $e->getMessage());
            }
        }
    }

    public function test_exact_decimal_normalization_handles_exponents_and_rejects_unsupported_precision(): void
    {
        foreach (['1e-3' => '0.001000000', '-0.000' => '0.000000000', '1.2300' => '1.230000000', '999999999999.123456789' => '999999999999.123456789'] as $input => $expected) {
            $this->assertSame($expected, MeasurementValue::normalize($input));
        }
        foreach (['NaN', 'INF', '1e99', '0.0000000001', '1000000000000'] as $input) {
            try {
                MeasurementValue::normalize($input);
                $this->fail('Invalid measurement accepted');
            } catch (MeasurementException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_history_is_scoped_paginated_and_available_to_active_members(): void
    {
        $admin = $this->admin();
        $link = $this->imported($admin);
        $collector = app(RemoteSensorCollector::class);
        for ($i = 0; $i < 52; $i++) {
            $this->fake([$this->state(extra: ['last_reported' => now()->subMinutes($i + 1)->toISOString()])]);
            $collector->collect($link);
        }
        $this->actingAs(User::factory()->create())->getJson('/api/v1/sensors/'.$link->sensor_id.'/measurements')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 52)->assertJsonMissingPath('data.0.home_assistant_link_id')->assertJsonPath('summary.status', 'fresh');
        $this->getJson('/api/v1/sensors/'.$link->sensor_id.'/measurements?page=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/sensors/'.$link->sensor_id.'/measurements?per_page=20&page=3')->assertJsonCount(12, 'data')->assertJsonPath('meta.per_page', 20)->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/v1/sensors/'.$link->sensor_id.'/measurements?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/sensors/'.$link->sensor_id.'/measurements?date_from=2020-01-01T00:00:00Z')->assertUnprocessable();
        $this->getJson('/api/v1/integrations/home-assistant/links')->assertForbidden();
    }

    public function test_link_versions_stale_admins_and_auth_sessions_are_enforced(): void
    {
        $admin = $this->admin();
        $link = $this->imported($admin);
        $url = '/api/v1/integrations/home-assistant/links/'.$link->id;
        $this->actingAs($admin)->patchJson($url, ['version' => 1, 'is_enabled' => false])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($url, ['version' => 1, 'is_enabled' => true])->assertConflict();
        $admin->fresh()->permissionRoles()->sync([AccessProfileSeeder::defaultProfile()->id]);
        try {
            app(RemoteSensorImport::class)->import($admin, ['sensor.temperature']);
            $this->fail('Stale administrator accepted');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('sensors', 1);
        }
    }

    public function test_entity_removal_preserves_history_and_reimport_reuses_the_channel(): void
    {
        $admin = $this->admin();
        $link = $this->imported($admin);
        app(RemoteSensorCollector::class)->collect($link);
        $url = '/api/v1/integrations/home-assistant/links/'.$link->id;
        $this->actingAs($admin)->deleteJson($url)->assertUnprocessable();
        $this->deleteJson($url, ['version' => 2])->assertConflict();
        $this->deleteJson($url, ['version' => 1])->assertNoContent();
        $this->assertSoftDeleted('home_assistant_links', ['id' => $link->id, 'is_enabled' => false, 'version' => 2]);
        $this->assertDatabaseCount('measurements', 1);
        $this->assertDatabaseCount('sensors', 1);
        $this->assertDatabaseCount('assets', 1);
        $this->assertSame($link->id, Measurement::sole()->home_assistant_link_id);
        $this->getJson('/api/v1/integrations/home-assistant/links')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/integrations/sources')->assertOk()->assertJsonPath('summary.total', 0);
        $this->getJson('/api/v1/integrations/home-assistant/entities')->assertOk()->assertJsonPath('data.0.imported', false);
        $this->getJson('/api/v1/sensors/'.$link->sensor_id.'/measurements')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('skipped', app(RemoteSensorCollector::class)->collect($link)['outcome']);
        $this->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => ['device:physical-device-1']])->assertCreated()->assertJsonPath('data.sensorsCreated', 0);
        $this->assertSame($link->sensor_id, HomeAssistantLink::sole()->sensor_id);
        $this->assertSame(3, HomeAssistantLink::sole()->version);
        $this->deleteJson($url, ['version' => 1])->assertConflict();
        $this->assertDatabaseCount('sensors', 1);
        $this->assertSame('duplicate', app(RemoteSensorCollector::class)->collect(HomeAssistantLink::sole())['outcome']);
    }

    public function test_entity_removal_requires_integration_permission_and_visible_location(): void
    {
        $admin = $this->admin();
        $link = $this->imported($admin);
        $url = '/api/v1/integrations/home-assistant/links/'.$link->id;
        $this->deleteJson($url, ['version' => 1])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->deleteJson($url, ['version' => 1])->assertForbidden();
        $site = Site::create(['name' => 'Private location']);
        $link->sensor->device->update(['site_id' => $site->id]);
        $restricted = User::factory()->create(['permission_grants' => ['integrations.manage'], 'location_access' => []]);
        $this->actingAs($restricted)->deleteJson($url, ['version' => 1])->assertNotFound();
        $this->assertNotNull(HomeAssistantLink::withoutGlobalScope('location_access')->find($link->id));
    }

    public function test_removal_during_a_provider_request_discards_the_in_flight_reading(): void
    {
        $admin = $this->admin();
        $link = $this->imported($admin);
        Http::swap(new Factory);
        Http::fake(['https://ha.example.test/api/states/*' => function () use ($admin, $link) {
            app(RemoteSensorImport::class)->remove($admin, $link, 1);

            return Http::response($this->state());
        }]);
        $this->assertSame('skipped', app(RemoteSensorCollector::class)->collect($link)['outcome']);
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_removed_entity_cannot_replace_a_new_channel_source_on_reimport(): void
    {
        $admin = $this->admin();
        $link = $this->imported($admin);
        $this->actingAs($admin)->deleteJson('/api/v1/integrations/home-assistant/links/'.$link->id, ['version' => 1])->assertNoContent();
        HttpMeasurementSource::create(['sensor_id' => $link->sensor_id, 'issuer_id' => $admin->id, 'issuer_version' => 0, 'token_hash' => hash('sha256', 'fictional-secret'), 'expires_at' => now()->addDay(), 'is_enabled' => true]);
        $this->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => ['device:physical-device-1']])->assertConflict()->assertJsonPath('error.code', 'measurements.errors.sourceConflict');
        $this->assertSoftDeleted('home_assistant_links', ['id' => $link->id]);
    }

    public function test_measurements_use_a_real_postgresql_hypertable_with_exact_precision(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('TimescaleDB integration requires PostgreSQL.');
        }
        $link = $this->imported($this->admin());
        $this->fake([$this->state(value: '999999999999.123456789')]);
        app(RemoteSensorCollector::class)->collect($link);
        $this->assertSame('999999999999.123456789', Measurement::sole()->value);
        $this->assertNotNull(DB::selectOne("SELECT hypertable_name FROM timescaledb_information.hypertables WHERE hypertable_name = 'measurements'"));
    }

    public function test_collector_command_schedules_enabled_links_and_deduplicates_polling(): void
    {
        $link = $this->imported($this->admin());
        $this->artisan('measurements:collect')->assertSuccessful();
        $this->assertDatabaseCount('measurements', 0);
        $this->workQueuedPolls();
        $this->assertDatabaseCount('measurements', 1);
        $this->artisan('measurements:collect')->assertSuccessful();
        $this->workQueuedPolls();
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_provider_failure_updates_only_sanitized_diagnostics(): void
    {
        $link = $this->imported($this->admin());
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('private body and token', 401)]);
        Log::spy();
        $result = app(RemoteSensorCollector::class)->collect($link);
        $this->assertSame('failed', $result['outcome']);
        $this->assertSame('measurements.errors.accessDenied', $link->fresh()->last_error);
        $this->assertDatabaseCount('measurements', 0);
        Log::shouldHaveReceived('info')->with('measurement.collection', ['link_uuid' => $link->id, 'sensor_uuid' => $link->sensor_id, 'outcome' => 'failed', 'code' => 'measurements.errors.accessDenied'])->once();
    }

    public function test_import_rolls_back_all_entities_when_an_existing_device_is_retired(): void
    {
        $admin = $this->admin();
        $link = $this->imported($admin);
        $link->sensor->device->update(['status' => 'retired']);
        $states = [$this->state('sensor.temperature_new'), $this->state('sensor.temperature_other')];
        $this->fake($states, [
            ['entity_id' => 'sensor.temperature_new', 'device_id' => 'new-device', 'name' => 'New device'],
            ['entity_id' => 'sensor.temperature_other', 'device_id' => 'physical-device-1', 'name' => 'Retired device'],
        ]);
        try {
            app(RemoteSensorImport::class)->import($admin, ['sensor.temperature_new', 'sensor.temperature_other']);
            $this->fail('Import into retired device accepted');
        } catch (MeasurementException $exception) {
            $this->assertSame('measurements.errors.deviceRetired', $exception->errorCode);
        }
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('sensors', 1);
        $this->assertDatabaseCount('home_assistant_links', 1);
    }

    public function test_import_marks_substrate_moisture_without_confusing_ambient_humidity(): void
    {
        $this->fake([
            $this->state('sensor.soil', '20', ['attributes' => ['device_class' => 'moisture', 'unit_of_measurement' => '%']]),
            $this->state('sensor.air', '50', ['attributes' => ['device_class' => 'humidity', 'unit_of_measurement' => '%']]),
        ]);
        app(RemoteSensorImport::class)->import($this->admin(), ['sensor.soil', 'sensor.air']);
        $types = MeasurementType::get()->keyBy('name');
        $this->assertSame('substrate_moisture', $types['moisture (%)']->nature);
        $this->assertSame('air_humidity', $types['humidity (%)']->nature);
    }

    public function test_whole_device_import_includes_binary_entities_and_fills_partial_devices(): void
    {
        $states = [$this->state(), $this->state('sensor.humidity', '45', ['attributes' => ['friendly_name' => 'Humidity', 'unit_of_measurement' => '%', 'device_class' => 'humidity']]),
            $this->state('switch.pump', 'off', ['attributes' => ['friendly_name' => 'Pump switch']]), $this->state('sensor.description', 'on')];
        $this->fake([$states[0]]);
        $actor = $this->admin();
        app(RemoteSensorImport::class)->import($actor, ['sensor.temperature']);
        $this->fake($states);
        $this->actingAs($actor)->getJson('/api/v1/integrations/home-assistant/entities?search=humidity')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'device:physical-device-1')->assertJsonPath('data.0.entityCount', 3)->assertJsonPath('data.0.importedCount', 1)->assertJsonPath('data.0.imported', false);
        $this->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => ['device:physical-device-1']])->assertCreated()->assertJsonPath('data.devicesCreated', 0)->assertJsonPath('data.sensorsCreated', 2)->assertJsonPath('data.alreadyImported', 1);
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('sensors', 3);
        $this->assertSame('binary', HomeAssistantLink::where('entity_id', 'switch.pump')->firstOrFail()->sensor->measurementType->nature);
        $this->assertSame('0', app(HomeAssistantClient::class)->states()[2]['state']);
        $link = HomeAssistantLink::where('entity_id', 'switch.pump')->firstOrFail();
        $this->assertSame('stored', app(RemoteSensorCollector::class)->collect($link)['outcome']);
        $this->assertDatabaseHas('measurements', ['sensor_id' => $link->sensor_id, 'value' => '0.000000000']);
        $event = [...$states[2], 'state' => 'on', 'last_reported' => now()->subSecond()->toISOString()];
        $this->assertSame('stored', app(RemoteSensorCollector::class)->collect($link, null, $event)['outcome']);
        $this->assertDatabaseHas('measurements', ['sensor_id' => $link->sensor_id, 'value' => '1.000000000']);

    }

    public function test_whole_device_import_accepts_long_orphan_entity_ids_without_overflowing_device_keys(): void
    {
        $entity = 'sensor.'.str_repeat('a', 248);
        $this->fake([$this->state($entity)], [['entity_id' => $entity, 'device_id' => null, 'name' => null]]);
        $key = 'entity:'.hash('sha256', $entity);
        $this->actingAs($this->admin())->getJson('/api/v1/integrations/home-assistant/entities')->assertOk()->assertJsonPath('data.0.id', $key);
        $this->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => [$key]])->assertCreated()->assertJsonPath('data.sensorsCreated', 1);
        $this->postJson('/api/v1/integrations/home-assistant/import', ['device_ids' => [$key]])->assertCreated()->assertJsonPath('data.alreadyImported', 1);
        $this->assertDatabaseHas('home_assistant_devices', ['source_key' => $key]);
        $this->assertDatabaseCount('assets', 1);
    }
}
