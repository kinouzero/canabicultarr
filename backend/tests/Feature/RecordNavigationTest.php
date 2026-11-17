<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecordNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hardware_direct_reads_require_current_visibility_and_never_control_the_device(): void
    {
        $asset = Asset::create(['name' => 'Navigation fixture']);
        $path = '/api/v1/assets/'.$asset->id;
        $this->getJson($path)->assertUnauthorized();
        $reader = User::factory()->create();
        $this->actingAs($reader)->getJson($path)->assertOk()->assertJsonPath('data.id', $asset->id)->assertJsonPath('data.name', 'Navigation fixture')->assertJsonPath('data.channelsCount', 0);
        $site = Site::create(['name' => 'Restricted navigation scope']);
        $restricted = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $this->actingAs($restricted)->getJson($path)->assertNotFound();
        $this->getJson('/api/v1/assets/'.Str::uuid())->assertNotFound();
        $this->assertDatabaseCount('pump_activations', 0);
    }

    public function test_location_direct_reads_do_not_broaden_a_restricted_scope(): void
    {
        $site = Site::create(['name' => 'Private navigation site', 'description' => 'Private location notes']);
        $other = Site::create(['name' => 'Other navigation site']);
        $path = '/api/v1/locations/site/'.$site->id;
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($path)->assertOk()->assertJsonPath('data.id', $site->id)->assertJsonPath('data.description', 'Private location notes');
        $restricted = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $other->id]]]);
        $this->actingAs($restricted)->getJson($path)->assertNotFound();
        $this->getJson('/api/v1/locations/unknown/'.$site->id)->assertNotFound();
        $this->getJson('/api/v1/locations/site/invalid-id')->assertNotFound();
        $room = $site->rooms()->create(['name' => 'Navigation room', 'code' => 'NAV-R']);
        $zone = $room->zones()->create(['name' => 'Navigation zone', 'code' => 'NAV-Z']);
        $zoneReader = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $zone->id]]]);
        $this->actingAs($zoneReader)->getJson($path)->assertNotFound();
        $this->getJson('/api/v1/locations/room/'.$room->id)->assertNotFound();
        $this->getJson('/api/v1/locations/zone/'.$zone->id)->assertOk()->assertJsonPath('data.id', $zone->id)->assertJsonPath('data.parentId', $room->id);
        $this->getJson('/api/v1/sites?search='.$site->id)->assertOk()->assertJsonPath('data.0.id', $site->id)->assertJsonPath('data.0.canSelect', false);

    }
}
