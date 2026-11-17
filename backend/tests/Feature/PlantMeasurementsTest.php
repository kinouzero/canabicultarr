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
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlantMeasurementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->travelTo(now()->startOfSecond());
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->site = Site::create(['name' => 'Visible site']);
        $this->actingAs($this->admin);
    }

    private function plant(): Plant
    {
        $strain = Strain::create(['name' => 'Reading strain '.Str::random(6)]);

        return app(PlantTracking::class)->create($this->admin, ['name' => 'Reading plant', 'request_id' => (string) Str::uuid(), 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $this->site->id]]);
    }

    private function sensor(Plant $plant): Sensor
    {
        $fixtureNow = now();
        $this->travel(-30)->days();
        $device = new Asset(['name' => 'Plant probe', 'category' => 'sensor']);
        $this->placeAsset($device, ['site_id' => $this->site->id]);
        $plant->devices()->attach($device);
        $type = MeasurementType::create(['name' => 'Air temperature', 'code' => 'temperature_'.Str::lower(Str::random(6)), 'unit' => '°C', 'nature' => 'temperature', 'decimal_places' => 1]);
        $sensor = new Sensor(['name' => 'Temperature', 'channel_key' => 'temperature', 'expected_interval_seconds' => 60]);
        $sensor->asset_id = $device->id;
        $sensor->measurement_type_id = $type->id;
        $sensor->save();

        $this->travelTo($fixtureNow);

        return $sensor;
    }

    private function reading(Sensor $sensor, string $value, int $seconds = 0, ?Site $site = null): void
    {
        $site ??= $this->site;
        $source = HomeAssistantLink::firstOrCreate(['sensor_id' => $sensor->id], ['instance_key' => 'fictional-fixture', 'entity_id' => 'sensor.fixture_'.$sensor->id]);
        $provenance = app(MeasurementProvenance::class)->attributes($sensor->device, $sensor, now()->subSeconds($seconds)->format('Y-m-d H:i:s.u'), $source, 'home_assistant');
        Measurement::create(['sensor_id' => $sensor->id, 'value' => $value, 'unit' => '°C', 'observed_at' => now()->subSeconds($seconds), 'received_at' => now(), 'location_snapshot' => ['site' => ['id' => $site->id, 'name' => $site->name]], ...($site->is($this->site) ? $provenance : [])]);
    }

    public function test_latest_readings_match_detail_list_and_both_dashboard_modes(): void
    {
        $plant = $this->plant();
        $sensor = $this->sensor($plant);
        $this->reading($sensor, '20.125', 60);
        $this->reading($sensor, '0', 10);
        $this->reading($sensor, '99', -60);
        foreach (['/api/v1/plants/'.$plant->id => 'data.measurements.0', '/api/v1/plants' => 'data.0.measurements.0', '/api/v1/sites/'.$this->site->id.'/plant-dashboard' => 'data.0.measurements.0', '/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1' => 'data.0.measurements.0'] as $url => $path) {
            $this->getJson($url)->assertOk()->assertJsonPath($path.'.sensorId', $sensor->id)
                ->assertJsonPath($path.'.value', '0.000000000')->assertJsonPath($path.'.unit', '°C')
                ->assertJsonPath($path.'.device.category', 'sensor')->assertJsonPath($path.'.measurementType.decimalPlaces', 1)->assertJsonPath($path.'.isStale', false)
                ->assertJsonPath($path.'.observedAt', now()->subSeconds(10)->toISOString());
        }
    }

    public function test_missing_old_and_paused_channels_are_explicit_without_inventing_values(): void
    {
        $plant = $this->plant();
        $sensor = $this->sensor($plant);
        $url = '/api/v1/plants/'.$plant->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.measurements.0.value', null)->assertJsonPath('data.measurements.0.observedAt', null)->assertJsonPath('data.measurements.0.isStale', false);
        $this->reading($sensor, '21.5', 121);
        $this->getJson($url)->assertOk()->assertJsonPath('data.measurements.0.isStale', true)->assertJsonPath('data.measurements.0.isEnabled', true);
        $sensor->update(['is_enabled' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.measurements.0.isEnabled', false)->assertJsonPath('data.measurements.0.value', '21.500000000');
        $sensor->update(['is_enabled' => true]);
        $sensor->device->update(['status' => 'maintenance']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.measurements.0.isEnabled', false);
        $plant->devices()->detach();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.measurements');
    }

    public function test_current_device_and_captured_measurement_location_are_both_scoped(): void
    {
        $plant = $this->plant();
        $sensor = $this->sensor($plant);
        $hidden = Site::create(['name' => 'Hidden site']);
        $plant->forceFill(['summary_sensor_ids' => [$sensor->id]])->save();
        $this->reading($sensor, '22', 60);
        $this->reading($sensor, '99', 0, $hidden);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($reader);
        app(LocationAccess::class)->clear();
        $url = '/api/v1/plants/'.$plant->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.measurements.0.value', '22.000000000')->assertDontSee('99.000000000');
        $this->placeAsset($sensor->device, ['site_id' => $hidden->id]);
        app(LocationAccess::class)->clear();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.measurements')->assertJsonPath('data.summarySensorIds', []);
    }

    public function test_summary_selection_is_per_plant_validated_versioned_and_cleans_up_removed_devices(): void
    {
        $plant = $this->plant();
        $first = $this->sensor($plant);
        $second = $this->sensor($plant);
        $other = $this->sensor($this->plant());
        $url = '/api/v1/plants/'.$plant->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.summarySensorIds', null);
        $this->patchJson($url, ['version' => $plant->version, 'summary_sensor_ids' => [strtoupper($first->id)]])->assertOk()->assertJsonPath('data.summarySensorIds', [$first->id])->assertJsonCount(2, 'data.measurements');
        $plant->refresh();
        $this->assertSame(2, $plant->version);
        $this->patchJson($url, ['version' => 1, 'summary_sensor_ids' => []])->assertConflict();
        foreach ([[$other->id], [(string) Str::uuid()]] as $invalid) {
            $this->patchJson($url, ['version' => $plant->version, 'summary_sensor_ids' => $invalid])->assertUnprocessable()->assertJsonPath('error.code', 'plants.errors.channelUnavailable');
        }
        $this->patchJson($url, ['version' => $plant->version, 'summary_sensor_ids' => [$first->id, strtoupper($first->id)]])->assertUnprocessable();
        $this->patchJson($url, ['version' => $plant->version, 'summary_sensor_ids' => []])->assertOk()->assertJsonPath('data.summarySensorIds', []);
        $this->patchJson($url, ['version' => $plant->fresh()->version, 'summary_sensor_ids' => null])->assertOk()->assertJsonPath('data.summarySensorIds', null);
        $this->patchJson($url, ['version' => $plant->fresh()->version, 'summary_sensor_ids' => [$first->id]])->assertOk();
        $this->patchJson($url, ['version' => $plant->fresh()->version, 'device_ids' => [$second->asset_id]])->assertOk()->assertJsonPath('data.summarySensorIds', []);
        $this->assertSame([], $plant->fresh()->summary_sensor_ids);
    }

    public function test_creation_selection_is_saved_and_bound_to_idempotent_payload(): void
    {
        $original = $this->plant();
        $sensor = $this->sensor($original);
        $input = ['name' => 'Selected summary', 'request_id' => (string) Str::uuid(), 'strain_id' => $original->strain_id, 'location' => ['type' => 'site', 'id' => $this->site->id], 'device_ids' => [$sensor->asset_id], 'summary_sensor_ids' => [$sensor->id]];
        $result = $this->postJson('/api/v1/plants', $input)->assertCreated()->assertJsonPath('data.summarySensorIds', [$sensor->id])->json('data');
        $this->postJson('/api/v1/plants', $input)->assertCreated()->assertJsonPath('data.id', $result['id']);
        $this->postJson('/api/v1/plants', [...$input, 'summary_sensor_ids' => []])->assertConflict();
        $reader = User::factory()->create();
        $this->actingAs($reader)->patchJson('/api/v1/plants/'.$result['id'], ['version' => 1, 'summary_sensor_ids' => []])->assertForbidden();
    }

    public function test_latest_measurements_are_batched_for_a_plant_page(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->reading($this->sensor($this->plant()), '23');
        }
        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            if (str_contains($query->sql, 'from "measurements"')) {
                $reads++;
            }
        });
        $this->getJson('/api/v1/plants')->assertOk()->assertJsonCount(4, 'data');
        $this->assertGreaterThan(0, $reads);
        $this->assertLessThanOrEqual(4, $reads);
    }
}
