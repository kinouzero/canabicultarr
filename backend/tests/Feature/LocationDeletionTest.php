<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletion_requires_management_and_location_access_and_preserves_linked_records(): void
    {
        $site = Site::create(['name' => 'Deletion site']);
        $room = new Room(['name' => 'Deletion room']);
        $room->site()->associate($site);
        $room->save();
        $zone = new Zone(['name' => 'Deletion zone']);
        $zone->room()->associate($room);
        $zone->save();
        $siteUrl = '/api/v1/sites/'.$site->id;
        $roomUrl = $siteUrl.'/rooms/'.$room->id;
        $zoneUrl = $roomUrl.'/zones/'.$zone->id;
        $this->deleteJson($siteUrl)->assertUnauthorized();
        $reader = User::factory()->create();
        foreach ([$siteUrl, $roomUrl, $zoneUrl] as $url) {
            $this->actingAs($reader)->deleteJson($url)->assertForbidden();
        }
        $outside = User::factory()->create(['permission_grants' => ['locations.manage'], 'location_access' => []]);
        $this->actingAs($outside)->deleteJson($siteUrl)->assertNotFound();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($admin)->deleteJson($siteUrl)->assertConflict()->assertJsonPath('error.code', 'locations.errors.inUse');
        $this->deleteJson($roomUrl)->assertConflict();
        $device = Asset::create(['name' => 'Preserved device']);
        $this->placeAsset($device, ['zone_id' => $zone->id]);
        $this->deleteJson($zoneUrl)->assertConflict();
        $this->assertDatabaseHas('asset_locations', ['asset_id' => $device->id, 'zone_id' => $zone->id]);
        $this->assertDatabaseHas('zones', ['id' => $zone->id]);
        $this->placeAsset($device, ['zone_id' => null]);
        $this->deleteJson($zoneUrl)->assertNoContent();
        $this->deleteJson($zoneUrl)->assertNotFound();
        $this->deleteJson($roomUrl)->assertNoContent();
        $this->deleteJson($siteUrl)->assertNoContent();
        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('assets', ['id' => $device->id]);
    }

    public function test_delegated_deletion_cannot_cross_parent_bindings_or_delete_navigation_ancestors(): void
    {
        $site = Site::create(['name' => 'Parent site']);
        $other = Site::create(['name' => 'Other site']);
        $room = new Room(['name' => 'Scoped room']);
        $room->site()->associate($site);
        $room->save();
        $delegate = User::factory()->create(['permission_grants' => ['locations.manage'], 'location_access' => [['type' => 'room', 'id' => $room->id]]]);
        $this->actingAs($delegate)->deleteJson('/api/v1/sites/'.$site->id)->assertForbidden();
        $this->deleteJson('/api/v1/sites/'.$other->id.'/rooms/'.$room->id)->assertNotFound();
        $this->deleteJson('/api/v1/sites/'.$site->id.'/rooms/'.$room->id)->assertNoContent();
        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }
}
