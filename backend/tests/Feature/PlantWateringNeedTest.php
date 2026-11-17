<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\HomeAssistantLink;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Plant;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\LocationAccess;
use App\Services\MeasurementProvenance;
use App\Services\PlantTracking;
use App\Services\PlantWateringSelection;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlantWateringNeedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private Strain $strain;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->site = Site::create(['name' => 'Watering need site']);
        $this->strain = Strain::create(['name' => 'Watering need strain']);
        $this->actingAs($this->admin);
    }

    private function plant(int $days = 0, array $extra = []): Plant
    {
        $plant = app(PlantTracking::class)->create($this->admin, ['name' => 'Watering plant', 'request_id' => (string) Str::uuid(), 'strain_id' => $this->strain->id, 'location' => ['type' => 'site', 'id' => $this->site->id], ...$extra]);
        $plant->forceFill(['phase' => 'growth', 'started_at' => now()->subDays($days), 'phase_started_at' => now(), 'phase_due_at' => now()->addWeeks(3)])->save();

        return $plant;
    }

    private function sensor(Plant $plant, bool $moisture = true): Sensor
    {
        $fixtureNow = now();
        $this->travel(-30)->days();
        $device = new Asset(['name' => 'Moisture device']);
        $this->placeAsset($device, ['site_id' => $this->site->id]);
        $plant->devices()->attach($device);
        $type = MeasurementType::create(['code' => 'test_'.Str::lower(Str::random(12)), 'name' => 'Moisture', 'unit' => '%', 'nature' => $moisture ? 'substrate_moisture' : 'air_humidity']);
        $sensor = new Sensor(['name' => 'Substrate moisture', 'channel_key' => 'moisture', 'expected_interval_seconds' => 3600]);
        $sensor->asset_id = $device->id;
        $sensor->measurement_type_id = $type->id;
        $sensor->save();

        $this->travelTo($fixtureNow);

        return $sensor;
    }

    private function reading(Sensor $sensor, float $percent, int $seconds = 0, ?array $location = null): void
    {
        $source = HomeAssistantLink::firstOrCreate(['sensor_id' => $sensor->id], ['instance_key' => 'fictional-fixture', 'entity_id' => 'sensor.fixture_'.$sensor->id]);
        $provenance = app(MeasurementProvenance::class)->attributes($sensor->device, $sensor, now()->subSeconds($seconds)->format('Y-m-d H:i:s.u'), $source, 'home_assistant');
        Measurement::create(['sensor_id' => $sensor->id, 'value' => (string) $percent, 'unit' => '%', 'observed_at' => now()->subSeconds($seconds), 'received_at' => now(), 'location_snapshot' => $location ?? ['site' => ['id' => $this->site->id, 'name' => $this->site->name]], ...($location === null ? $provenance : [])]);
    }

    private function plantResponse(Plant $plant)
    {
        $response = $this->getJson('/api/v1/plants/'.$plant->id)->assertOk();
        $selected = app(PlantWateringSelection::class)->due(Plant::whereKey($plant->id), now()->toImmutable())->exists();
        $this->assertSame($response->json('data.watering.need.needed'), $selected, 'Dashboard selection must match the authorized plant record.');

        return $response;
    }

    public function test_default_delay_starts_from_actual_watering_or_tracking_start_and_resets(): void
    {
        $plant = $this->plant(3);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.needed', true)->assertJsonPath('data.watering.need.reason', 'elapsed')->assertJsonPath('data.watering.need.alertDays', 3)->assertJsonPath('data.watering.need.moistureThreshold', 35);
        $this->postJson('/api/v1/plants/'.$plant->id.'/waterings', ['version' => $plant->version, 'request_id' => (string) Str::uuid(), 'with_products' => false])->assertOk()->assertJsonPath('data.watering.need.needed', false);
        $this->travel(3)->days();
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.reason', 'elapsed');
        $plant->forceFill(['started_at' => null])->save();
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.needed', true);
        $plant->events()->where('kind', 'watered')->delete();
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.needed', false)->assertJsonPath('data.watering.need.timerDueAt', null);
    }

    public function test_authoritative_moisture_triggers_early_and_suppresses_elapsed_delay(): void
    {
        $early = $this->plant();
        $sensor = $this->sensor($early);
        $this->reading($sensor, 34.9, 10);
        $this->plantResponse($early)->assertJsonPath('data.watering.need.needed', true)->assertJsonPath('data.watering.need.reason', 'moisture');
        $old = $this->plant(10);
        $wet = $this->sensor($old);
        $this->reading($wet, 35, 10);
        $this->plantResponse($old)->assertJsonPath('data.watering.need.needed', false)->assertJsonPath('data.watering.need.measurement.percent', 35);
        $this->reading($wet, 90);
        $this->plantResponse($old)->assertJsonPath('data.watering.need.needed', false)->assertJsonPath('data.watering.need.measurement.percent', 90);
        $this->reading($sensor, 70);
        $this->plantResponse($early)->assertJsonPath('data.watering.need.needed', false);
    }

    public function test_stale_disabled_ambient_unlinked_unknown_interval_and_invalid_measurements_fall_back(): void
    {
        foreach (['stale', 'disabled', 'ambient', 'unlinked', 'unknown_interval', 'invalid', 'retired', 'maintenance', 'future'] as $case) {
            $plant = $this->plant(10);
            $sensor = $this->sensor($plant, $case !== 'ambient');
            $this->reading($sensor, $case === 'invalid' ? 101 : 90, $case === 'stale' ? 7201 : ($case === 'future' ? -10 : 0));
            if ($case === 'disabled') {
                $sensor->update(['is_enabled' => false]);
            }
            if ($case === 'unknown_interval') {
                $sensor->update(['expected_interval_seconds' => null]);
            }
            if ($case === 'unlinked') {
                $plant->devices()->detach();
            }
            if (in_array($case, ['retired', 'maintenance'])) {
                $sensor->device->update(['status' => $case]);
            }
            $this->plantResponse($plant)->assertJsonPath('data.watering.need.reason', 'elapsed')->assertJsonPath('data.watering.need.measurement', null);
        }
    }

    public function test_watering_invalidates_older_readings_and_new_readings_can_request_again(): void
    {
        $plant = $this->plant(10);
        $sensor = $this->sensor($plant);
        $this->reading($sensor, 10, 10);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.needed', true);
        $this->postJson('/api/v1/plants/'.$plant->id.'/waterings', ['version' => 1, 'request_id' => (string) Str::uuid(), 'with_products' => false])->assertOk()->assertJsonPath('data.watering.need.needed', false)->assertJsonPath('data.watering.need.measurement', null);
        $this->reading($sensor, 20);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.needed', false);
        $this->travel(1)->seconds();
        $this->reading($sensor, 20);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.reason', 'moisture');
    }

    public function test_multiple_sensors_use_lowest_usable_value_and_post_harvest_and_planned_are_excluded(): void
    {
        $plant = $this->plant();
        $this->reading($this->sensor($plant), 90);
        $this->reading($this->sensor($plant), 20);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.needed', true)->assertJsonPath('data.watering.need.measurement.percent', 20);
        foreach (['planned', 'hanging_drying', 'rack_drying', 'curing', 'maturation', 'archived'] as $phase) {
            $plant->forceFill(['phase' => $phase])->save();
            $this->plantResponse($plant)->assertJsonPath('data.watering.status', 'unavailable')->assertJsonPath('data.watering.need.needed', false);
        }
    }

    public function test_settings_authorization_validation_versions_and_location_inheritance(): void
    {
        $settings = $this->getJson('/api/v1/lifecycle-settings')->assertOk()->assertJsonPath('data.wateringDefaults.alertDays', 3)->assertJsonPath('data.wateringDefaults.moistureThreshold', 35)->json('data');
        $input = ['version' => $settings['version'], 'durations' => $settings['durations'], 'watering_alert_days' => 7, 'watering_moisture_threshold' => 42];
        $this->patchJson('/api/v1/lifecycle-settings', $input)->assertOk()->assertJsonPath('data.wateringDefaults.alertDays', 7)->assertJsonPath('data.wateringDefaults.moistureThreshold', 42);
        $this->patchJson('/api/v1/lifecycle-settings', $input)->assertConflict();
        $input['version']++;
        foreach ([['watering_alert_days' => 0], ['watering_alert_days' => 1.5], ['watering_moisture_threshold' => 101], ['watering_moisture_threshold' => 35.001], ['watering_moisture_threshold' => null]] as $invalid) {
            $this->patchJson('/api/v1/lifecycle-settings', [...$input, ...$invalid])->assertUnprocessable();
        }
        $room = $this->site->rooms()->create(['name' => 'Watering room']);
        $zone = $room->zones()->create(['name' => 'Watering zone']);
        $plant = $this->plant(4);
        $plant->forceFill(['site_id' => null, 'zone_id' => $zone->id])->save();
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.alertDays', 7)->assertJsonPath('data.watering.need.needed', false);
        $this->site->update(['watering_alert_days' => 6]);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.alertDays', 6);
        $room->update(['watering_alert_days' => 5]);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.alertDays', 5);
        $this->patchJson('/api/v1/sites/'.$this->site->id.'/rooms/'.$room->id.'/zones/'.$zone->id, ['name' => $zone->name, 'watering_alert_days' => 2])->assertOk()->assertJsonPath('data.wateringAlertDays', 2);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.alertDays', 2)->assertJsonPath('data.watering.need.needed', true);
        $this->patchJson('/api/v1/sites/'.$this->site->id.'/rooms/'.$room->id.'/zones/'.$zone->id, ['name' => $zone->name, 'watering_alert_days' => null])->assertOk();
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.alertDays', 5);
        $this->patchJson('/api/v1/sites/'.$this->site->id.'/rooms/'.$room->id.'/zones/'.$zone->id, ['name' => $zone->name, 'watering_alert_days' => 0])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/lifecycle-settings', $input)->assertForbidden();
    }

    public function test_freshness_boundaries_wrong_units_and_numeric_minimum_match_dashboard_selection(): void
    {
        $plant = $this->plant(10);
        $sensor = $this->sensor($plant);
        $sensor->update(['expected_interval_seconds' => 10]);
        $this->reading($sensor, 80, 120);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.needed', false);
        $this->travelTo(now()->addMicrosecond());
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.reason', 'elapsed');
        $this->reading($sensor, 80);
        $wrong = $this->sensor($plant);
        $this->reading($wrong, 9);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.reason', 'moisture');
        Measurement::where('sensor_id', $wrong->id)->update(['unit' => 'V']);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.needed', false);
        // A newer unusable sample must not revive an older usable dry reading.
        $this->travel(1)->seconds();
        $this->reading($sensor, 101);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.reason', 'elapsed');
    }

    public function test_due_queue_unions_lifecycle_and_watering_before_group_pagination_without_duplicates(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->plant(4, ['name' => sprintf('Due %02d', $i)]);
        }
        $future = $this->plant();
        $this->reading($this->sensor($future), 10);
        $wet = $this->plant(10);
        $this->reading($this->sensor($wet), 90);
        $overdue = $this->plant(10);
        $overdue->forceFill(['phase_due_at' => now()->subDay()])->save();
        $outside = Site::create(['name' => 'Outside']);
        $this->plant(10, ['location' => ['type' => 'site', 'id' => $outside->id]]);
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?due_only=1&grouped=1';
        $this->getJson($url)->assertOk()->assertJsonPath('summary.due', 24)->assertJsonPath('meta.total', 24)->assertJsonCount(20, 'data');
        $this->getJson($url.'&'.http_build_query(['pages' => ['site:'.$this->site->id => 2]]))->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('summary.due', 24);
    }

    public function test_captured_measurement_location_and_current_device_scope_both_apply(): void
    {
        $plant = $this->plant(10);
        $sensor = $this->sensor($plant);
        $other = Site::create(['name' => 'Hidden measurement site']);
        $this->reading($sensor, 90, 0, ['site' => ['id' => $other->id, 'name' => $other->name]]);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($reader);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.reason', 'elapsed')->assertJsonPath('data.watering.need.measurement', null);
        $this->travel(1)->seconds();
        $this->reading($sensor, 90);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.needed', false);
        $this->placeAsset($sensor->device, ['site_id' => $other->id]);
        app(LocationAccess::class)->clear();
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.reason', 'elapsed')->assertJsonPath('data.watering.need.measurement', null);
        $this->getJson('/api/v1/sites/'.$other->id.'/plant-dashboard')->assertNotFound();
    }

    public function test_suspect_and_invalid_moisture_do_not_override_usable_readings_or_the_timer(): void
    {
        $plant = $this->plant();
        $sensor = $this->sensor($plant);
        $this->reading($sensor, 20, 2);
        $this->reading($sensor, 90, 1);
        $latest = Measurement::where('sensor_id', $sensor->id)->orderByDesc('observed_at')->firstOrFail();
        $latest->update(['quality' => 'suspect']);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.reason', 'moisture');
        Measurement::where('sensor_id', $sensor->id)->update(['quality' => 'invalid']);
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.needed', false);
        $plant->forceFill(['started_at' => now()->subDays(4)])->save();
        $this->plantResponse($plant)->assertJsonPath('data.watering.need.reason', 'elapsed');
    }
}
