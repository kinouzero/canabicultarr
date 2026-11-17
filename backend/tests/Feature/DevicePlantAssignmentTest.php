<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\MeasurementType;
use App\Models\Plant;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\LocationAccess;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DevicePlantAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Asset $device;

    private Sensor $sensor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($this->admin);
        $this->device = Asset::create(['name' => 'Linked probe', 'category' => 'sensor']);
        $type = MeasurementType::create(['name' => 'Soil moisture', 'code' => 'soil_moisture', 'nature' => 'substrate_moisture', 'unit' => '%']);
        $this->sensor = new Sensor(['name' => 'Soil reading', 'channel_key' => 'soil', 'expected_interval_seconds' => 3600]);
        $this->sensor->device()->associate($this->device);
        $this->sensor->measurementType()->associate($type);
        $this->sensor->save();
    }

    private function plant(Site $site, string $name): Plant
    {
        $strain = Strain::firstOrCreate(['name_key' => 'assignment strain'], ['name' => 'Assignment strain']);

        return app(PlantTracking::class)->create($this->admin, ['name' => $name, 'request_id' => (string) Str::uuid(), 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id]]);
    }

    public function test_linking_and_unlinking_a_device_updates_hardware_channels_and_site_measurements(): void
    {
        $site = Site::create(['name' => 'Plant site']);
        $plant = $this->plant($site, 'Assigned plant');
        $url = '/api/v1/plants/'.$plant->id;
        $this->patchJson($url, ['version' => $plant->version, 'device_ids' => [$this->device->id]])->assertOk();
        $asset = $this->getJson('/api/v1/assets')->assertOk()->assertJsonPath('data.0.location', null)->assertJsonPath('data.0.plants.0.name', $plant->name)->assertJsonPath('data.0.plants.0.location.id', $site->id);
        $this->getJson('/api/v1/sensors')->assertOk()->assertJsonPath('data.0.device.plants', $asset->json('data.0.plants'));
        $this->getJson('/api/v1/measurements/overview?site_id='.$site->id)->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.sensor.device.plants.0.id', $plant->id);
        $other = Site::create(['name' => 'New plant site']);
        $this->patchJson($url, ['version' => $plant->fresh()->version, 'location' => ['type' => 'site', 'id' => $other->id]])->assertOk();
        $this->getJson('/api/v1/assets')->assertJsonPath('data.0.location', null)->assertJsonPath('data.0.plants.0.location.id', $other->id);
        $this->getJson('/api/v1/measurements/overview?site_id='.$site->id)->assertJsonPath('summary.total', 0);
        $this->getJson('/api/v1/measurements/overview?site_id='.$other->id)->assertJsonPath('summary.total', 1);
        $this->patchJson($url, ['version' => $plant->fresh()->version, 'device_ids' => []])->assertOk();
        $this->getJson('/api/v1/assets')->assertJsonPath('data.0.plants', []);
        $this->getJson('/api/v1/sensors')->assertJsonPath('data.0.device.plants', []);
        $this->assertNull($this->device->fresh()->site_id);
        $this->assertDatabaseCount('asset_assignments', 0);
    }

    public function test_shared_devices_keep_direct_placement_and_all_linked_locations(): void
    {
        $one = Site::create(['name' => 'First site']);
        $two = Site::create(['name' => 'Second site']);
        $first = $this->plant($one, 'First plant');
        $second = $this->plant($two, 'Second plant');
        $first->devices()->attach($this->device);
        $second->devices()->attach($this->device);
        $this->placeAsset($this->device, ['site_id' => $one->id]);
        $this->getJson('/api/v1/assets')->assertJsonPath('data.0.locations.0.id', $one->id)->assertJsonCount(2, 'data.0.plants')->assertJsonPath('data.0.plants.1.location.id', $two->id)->assertJsonMissingPath('data.0.plants.0.notes');
        foreach ([$one, $two] as $site) {
            $this->getJson('/api/v1/measurements/overview?site_id='.$site->id)->assertJsonPath('summary.total', 1);
        }
    }

    public function test_assignment_context_never_exposes_geographically_hidden_plants(): void
    {
        $visible = Site::create(['name' => 'Visible site']);
        $hidden = Site::create(['name' => 'Hidden site']);
        $first = $this->plant($visible, 'Visible plant');
        $second = $this->plant($hidden, 'Hidden plant');
        $first->devices()->attach($this->device);
        $second->devices()->attach($this->device);
        $this->placeAsset($this->device, ['site_id' => $visible->id]);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $visible->id]]]);
        app(LocationAccess::class)->clear();
        $this->actingAs($reader)->getJson('/api/v1/assets')->assertOk()->assertJsonCount(0, 'data')->assertJsonMissing(['name' => 'Hidden plant']);
        $this->getJson('/api/v1/sensors')->assertOk()->assertJsonCount(0, 'data')->assertJsonMissing(['name' => 'Hidden plant']);
        $this->getJson('/api/v1/measurements/overview?site_id='.$hidden->id)->assertOk()->assertJsonPath('summary.total', 0);
    }
}
