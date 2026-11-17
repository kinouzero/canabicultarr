<?php

namespace Tests\Feature;

use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationPlantsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Strain $strain;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->strain = Strain::create(['name' => 'Location strain']);
        $this->actingAs($this->admin);
    }

    private function plant(string $type, string $id, ?string $name = null): Plant
    {
        return app(PlantTracking::class)->create($this->admin, ['name' => $name, 'planned' => true, 'strain_id' => $this->strain->id, 'location' => ['type' => $type, 'id' => $id], 'request_id' => (string) Str::uuid()]);
    }

    public function test_location_summary_includes_current_descendants_and_updates_after_a_move(): void
    {
        $site = Site::create(['name' => 'Plant site']);
        $room = $site->rooms()->create(['name' => 'Plant room']);
        $zone = $room->zones()->create(['name' => 'Plant zone']);
        $direct = $this->plant('site', $site->id, 'Direct plant');
        $growing = $this->plant('room', $room->id, 'Room plant');
        $growing->forceFill(['phase' => 'growth'])->save();
        $nested = $this->plant('zone', $zone->id);
        $archived = $this->plant('zone', $zone->id, 'Archived plant');
        $archived->forceFill(['phase' => 'archived'])->save();
        $this->getJson('/api/v1/locations/site/'.$site->id.'/plants')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/locations/site/'.$site->id.'/plants?status=active')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $growing->id);
        $this->getJson('/api/v1/locations/site/'.$site->id.'/plants?status=planned')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/locations/site/'.$site->id.'/plants?status=archived')->assertUnprocessable();
        $this->getJson('/api/v1/locations/room/'.$room->id.'/plants')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/locations/zone/'.$zone->id.'/plants')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $nested->id)->assertJsonPath('data.0.strain.name', $this->strain->name)->assertJsonPath('data.0.name', $nested->displayName());
        app(PlantTracking::class)->update($this->admin, $nested, ['version' => 1, 'location' => ['type' => 'site', 'id' => $site->id]]);
        $this->getJson('/api/v1/locations/zone/'.$zone->id.'/plants')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/locations/site/'.$site->id.'/plants')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame($site->id, $direct->fresh()->site_id);
    }

    public function test_navigation_ancestors_and_hidden_locations_never_expose_their_plant_list(): void
    {
        $site = Site::create(['name' => 'Restricted site']);
        $room = $site->rooms()->create(['name' => 'Restricted room']);
        $zone = $room->zones()->create(['name' => 'Allowed zone']);
        $hidden = $room->zones()->create(['name' => 'Hidden zone']);
        $plant = $this->plant('zone', $zone->id);
        $this->plant('zone', $hidden->id, 'Secret plant');
        $reader = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $zone->id]]]);
        $this->actingAs($reader)->getJson('/api/v1/locations/zone/'.$zone->id.'/plants')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $plant->id);
        foreach ([['site', $site->id], ['room', $room->id], ['zone', $hidden->id], ['zone', (string) Str::uuid()]] as [$kind, $id]) {
            $this->getJson('/api/v1/locations/'.$kind.'/'.$id.'/plants')->assertNotFound();
        }
        $reader->forceFill(['location_access' => [['type' => 'room', 'id' => $room->id]]])->save();
        $this->actingAs($reader->fresh())->getJson('/api/v1/locations/room/'.$room->id.'/plants')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_location_plant_pages_are_bounded_and_have_no_per_row_queries(): void
    {
        $site = Site::create(['name' => 'Paged site']);
        for ($index = 0; $index < 23; $index++) {
            $this->plant('site', $site->id);
        }
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/locations/site/'.$site->id.'/plants?per_page=10&page=2')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 23)->assertJsonPath('meta.last_page', 3);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(18, count($queries));
        $response->assertJsonMissingPath('data.0.notes')->assertJsonPath('data.0.watering.status', 'unavailable')->assertJsonPath('data.0.measurements', []);
        $this->getJson('/api/v1/locations/site/'.$site->id.'/plants?per_page=1000')->assertUnprocessable();
        Auth::forgetGuards();
        $this->getJson('/api/v1/locations/site/'.$site->id.'/plants')->assertUnauthorized();
    }
}
