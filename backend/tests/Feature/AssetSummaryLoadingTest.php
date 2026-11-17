<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\MeasurementType;
use App\Models\Plant;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\AssetContexts;
use App\Services\LocationAccess;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetSummaryLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_summaries_are_bounded_per_device_and_full_assignments_remain_available(): void
    {
        $user = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($user);
        $site = Site::create(['name' => 'Summary site']);
        $strain = Strain::create(['name' => 'Summary strain', 'notes' => str_repeat('Large catalog notes. ', 1000)]);
        $devices = [Asset::create(['name' => 'Shared light']), Asset::create(['name' => 'Shared sensor'])];
        $links = [];
        for ($i = 0; $i < 240; $i++) {
            $plant = Plant::create(['name' => $i % 2 ? 'Named plant '.$i : null, 'strain_id' => $strain->id, 'site_id' => $site->id, 'phase' => 'growth', 'started_at' => now(), 'phase_started_at' => now(), 'duration_snapshot' => ['growth' => 21], 'creation_key' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64)]);
            foreach ($devices as $device) {
                $links[] = ['plant_id' => $plant->id, 'asset_id' => $device->id];
            }
        }
        // Build the final snapshot once; pivot-history behavior has dedicated tests.
        foreach (array_chunk($links, 200) as $batch) {
            DB::table('plant_device')->insert($batch);
        }
        foreach ($devices as $device) {
            app(AssetContexts::class)->capture($device->id);
        }
        $type = MeasurementType::create(['name' => 'Temperature', 'code' => 'temperature', 'unit' => 'C', 'decimal_places' => 1]);
        $sensor = new Sensor(['name' => 'Channel', 'channel_key' => 'test']);
        $sensor->device()->associate($devices[0]);
        $sensor->measurementType()->associate($type);
        $sensor->save();
        $baseline = Asset::with([...Asset::locationRelations(), 'plants.strain:id,name', ...array_map(fn ($relation) => 'plants.'.$relation, Plant::locationRelations())])->get();
        $summary = app(AssetAdministration::class)->search([]);
        $this->assertCount(2, $summary);
        foreach ($summary as $device) {
            $this->assertCount(3, $device->summaryPlants);
            $this->assertSame(240, $device->plants_count);
            $this->assertFalse($device->relationLoaded('plants'));
            $this->assertFalse($device->summaryPlants->first()->strain->offsetExists('notes'));
            $this->assertSame(array_slice($baseline->firstWhere('id', $device->id)->linkedPlantDetails(), 0, 3), $device->summaryPlantDetails());
        }
        $beforeBytes = strlen(json_encode($baseline->map->linkedPlantDetails()));
        $afterBytes = strlen(json_encode($summary->map(fn ($device) => ['plants' => $device->summaryPlantDetails(), 'count' => $device->plants_count])));
        $this->assertLessThan($beforeBytes / 10, $afterBytes);
        $this->getJson('/api/v1/assets')->assertOk()->assertJsonCount(3, 'data.0.plants')->assertJsonPath('data.0.plantsCount', 240);
        $this->getJson('/api/v1/sensors/'.$sensor->id)->assertOk()->assertJsonCount(3, 'data.device.plants')->assertJsonPath('data.device.plantsCount', 240);
        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson('/api/v1/assets/'.$devices[0]->id.'/plants?per_page=100&page='.$page)->assertOk()->assertJsonPath('meta.total', 240);
            $ids = [...$ids, ...array_column($response->json('data'), 'id')];
        }
        $this->assertCount(240, array_unique($ids));
        $this->assertCount(240, $devices[0]->fresh()->plants);
        $this->getJson('/api/v1/assets/'.$devices[0]->id.'/plants?per_page=101')->assertUnprocessable();
        $reader = User::factory()->create(['location_access' => []]);
        app(LocationAccess::class)->clear();
        $this->actingAs($reader)->getJson('/api/v1/assets/'.$devices[0]->id.'/plants')->assertNotFound();

    }
}
