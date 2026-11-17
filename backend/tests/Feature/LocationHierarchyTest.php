<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use App\Services\LocationAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function hierarchy(): array
    {
        $site = Site::create(['name' => 'Test Site']);
        $room = new Room(['name' => 'Test Room']);
        $room->site()->associate($site);
        $room->save();
        $zone = new Zone(['name' => 'Test Zone']);
        $zone->room()->associate($room);
        $zone->save();

        return [$site, $room, $zone];
    }

    public function test_guest_cannot_read_or_change_any_level(): void
    {
        [$site, $room, $zone] = $this->hierarchy();
        foreach (['/api/v1/sites', "/api/v1/sites/$site->id/rooms", "/api/v1/sites/$site->id/rooms/$room->id/zones"] as $url) {
            $this->getJson($url)->assertUnauthorized();
            $this->postJson($url, ['name' => 'Denied'])->assertUnauthorized();
        }
        foreach (["/api/v1/sites/$site->id", "/api/v1/sites/$site->id/rooms/$room->id", "/api/v1/sites/$site->id/rooms/$room->id/zones/$zone->id"] as $url) {
            $this->patchJson($url, ['name' => 'Denied'])->assertUnauthorized();
        }
    }

    public function test_member_reads_hierarchy_but_cannot_create_or_edit(): void
    {
        [$site, $room, $zone] = $this->hierarchy();
        $this->actingAs(User::factory()->create());
        $urls = ['/api/v1/sites', "/api/v1/sites/$site->id/rooms", "/api/v1/sites/$site->id/rooms/$room->id/zones"];
        foreach ($urls as $index => $url) {
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.kind', ['site', 'room', 'zone'][$index])
                ->assertJsonPath('data.0.childrenCount', $index < 2 ? 1 : 0)
                ->assertJsonMissingPath('data.0.name_key');
            $this->postJson($url, ['name' => 'Denied'])->assertForbidden();
        }
        $this->patchJson('/api/v1/sites/'.$site->id, ['name' => 'Denied'])->assertForbidden();
        $this->patchJson("/api/v1/sites/$site->id/rooms/$room->id", ['name' => 'Denied'])->assertForbidden();
        $this->patchJson("/api/v1/sites/$site->id/rooms/$room->id/zones/$zone->id", ['name' => 'Denied'])->assertForbidden();
    }

    public function test_admin_creates_all_levels_with_server_uuids_and_parents(): void
    {
        $this->actingAs($this->admin());
        $forged = (string) Str::uuid();
        $site = $this->postJson('/api/v1/sites', ['name' => ' Site A ', 'description' => 'A description', 'id' => $forged, 'name_key' => 'forged'])
            ->assertCreated()->assertJsonPath('data.name', 'Site A')->assertJsonPath('data.parentId', null)->json('data');
        $this->assertTrue(Str::isUuid($site['id']));
        $this->assertNotSame($forged, $site['id']);
        $room = $this->postJson('/api/v1/sites/'.$site['id'].'/rooms', ['name' => 'Room A', 'site_id' => $forged])
            ->assertCreated()->assertJsonPath('data.parentId', $site['id'])->json('data');
        $zone = $this->postJson('/api/v1/sites/'.$site['id'].'/rooms/'.$room['id'].'/zones', ['name' => 'Zone A', 'room_id' => $forged])
            ->assertCreated()->assertJsonPath('data.parentId', $room['id'])->json('data');
        $this->assertTrue(Str::isUuid($room['id']));
        $this->assertTrue(Str::isUuid($zone['id']));
        $this->assertSame('site a', Site::findOrFail($site['id'])->name_key);
    }

    public function test_names_are_case_insensitive_and_unique_only_within_their_parent(): void
    {
        [$site, $room, $zone] = $this->hierarchy();
        $other = Site::create(['name' => 'Other Site']);
        $this->actingAs($this->admin());
        $this->postJson('/api/v1/sites', ['name' => ' test SITE '])->assertConflict()->assertJsonPath('error.code', 'locations.errors.nameTaken');
        $this->postJson("/api/v1/sites/$site->id/rooms", ['name' => 'TEST ROOM'])->assertConflict();
        $otherRoom = $this->postJson("/api/v1/sites/$other->id/rooms", ['name' => 'TEST ROOM'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/sites/$site->id/rooms/$room->id/zones", ['name' => 'test ZONE'])->assertConflict();
        $this->postJson("/api/v1/sites/$other->id/rooms/$otherRoom/zones", ['name' => 'test ZONE'])->assertCreated();
        $this->patchJson('/api/v1/sites/'.$other->id, ['name' => 'TEST SITE'])->assertConflict();
        $this->assertSame('Other Site', $other->fresh()->name);
    }

    public function test_cross_parent_routes_cannot_read_create_or_edit_descendants(): void
    {
        [$site, $room, $zone] = $this->hierarchy();
        $other = Site::create(['name' => 'Other Site']);
        $otherRoom = new Room(['name' => 'Other Room']);
        $otherRoom->site()->associate($site);
        $otherRoom->save();
        $this->actingAs($this->admin());
        $this->getJson("/api/v1/sites/$other->id/rooms/$room->id/zones")->assertNotFound();
        $this->postJson("/api/v1/sites/$other->id/rooms/$room->id/zones", ['name' => 'Denied'])->assertNotFound();
        $this->patchJson("/api/v1/sites/$other->id/rooms/$room->id", ['name' => 'Denied'])->assertNotFound();
        $this->patchJson("/api/v1/sites/$site->id/rooms/$otherRoom->id/zones/$zone->id", ['name' => 'Denied'])->assertNotFound();
        $this->assertSame('Test Room', $room->fresh()->name);
        $this->assertSame('Test Zone', $zone->fresh()->name);
    }

    public function test_edit_preserves_uuid_and_parent_and_returns_current_child_count(): void
    {
        [$site, $room, $zone] = $this->hierarchy();
        $other = Site::create(['name' => 'Other Site']);
        $this->actingAs($this->admin());
        $this->patchJson('/api/v1/sites/'.$site->id, ['name' => 'Site Updated', 'description' => 'Changed'])
            ->assertOk()->assertJsonPath('data.id', $site->id)->assertJsonPath('data.childrenCount', 1);
        $this->patchJson("/api/v1/sites/$site->id/rooms/$room->id", ['name' => 'Room Updated', 'description' => null, 'site_id' => $other->id])
            ->assertOk()->assertJsonPath('data.parentId', $site->id)->assertJsonPath('data.childrenCount', 1);
        $this->patchJson("/api/v1/sites/$site->id/rooms/$room->id/zones/$zone->id", ['name' => 'Zone Updated', 'description' => 'Notes'])
            ->assertOk()->assertJsonPath('data.id', $zone->id)->assertJsonPath('data.parentId', $room->id);
    }

    public function test_invalid_fields_and_unknown_or_malformed_uuids_are_rejected(): void
    {
        $this->actingAs($this->admin());
        foreach ([['name' => '   '], ['name' => ['invalid']], ['name' => str_repeat('a', 121)], ['name' => 'Name', 'description' => str_repeat('x', 2001)]] as $input) {
            $this->postJson('/api/v1/sites', $input)->assertUnprocessable();
        }
        $this->getJson('/api/v1/sites?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/sites?search='.str_repeat('a', 121))->assertUnprocessable();
        $this->getJson('/api/v1/sites/not-a-uuid/rooms')->assertNotFound()->assertJsonPath('error.code', 'auth.errors.notFound');
        $this->getJson('/api/v1/sites/'.Str::uuid().'/rooms')->assertNotFound();
    }

    public function test_search_pagination_and_child_lists_stay_scoped(): void
    {
        [$site, $room, $zone] = $this->hierarchy();
        for ($index = 0; $index < 21; $index++) {
            Site::create(['name' => sprintf('Location %02d', $index)]);
        }
        Site::create(['name' => 'Literal _% Étage']);
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/sites')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23);
        $this->getJson('/api/v1/sites?page=2')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/sites?search='.urlencode('_% éTAGE'))->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/sites/$site->id/rooms?search=TEST")->assertJsonCount(1, 'data');
        $empty = Site::create(['name' => 'Empty Site']);
        $this->getJson("/api/v1/sites/$empty->id/rooms")->assertJsonCount(0, 'data');
    }

    public function test_inactive_and_revoked_sessions_cannot_read_locations(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user)->getJson('/api/v1/sites')->assertUnauthorized();
        $user->forceFill(['is_active' => true, 'session_version' => 1])->save();
        $this->actingAs($user)->withSession(['auth_version' => 0])->getJson('/api/v1/sites')->assertUnauthorized();
    }

    public function test_service_rechecks_actor_permissions_before_writing(): void
    {
        $admin = $this->admin();
        $stale = clone $admin;
        $admin->permission_role_ids = [AccessProfileSeeder::defaultProfile()->id];
        $admin->save();
        try {
            app(LocationAdministration::class)->create($stale, new Site, ['name' => 'Denied']);
            $this->fail('Stale administrator must be rejected.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('sites', 0);
        }
    }

    public function test_foreign_keys_prevent_removing_a_parent_with_children(): void
    {
        [$site, $room] = $this->hierarchy();
        foreach ([$site, $room] as $parent) {
            try {
                DB::transaction(fn () => $parent->delete());
                $this->fail('Parent deletion must be restricted.');
            } catch (QueryException) {
                $this->assertTrue($parent->newQuery()->whereKey($parent->id)->exists());
            }
        }
    }

    public function test_audit_records_identifiers_and_field_names_without_location_values(): void
    {
        Log::spy();
        $admin = $this->admin();
        $this->actingAs($admin);
        $id = $this->postJson('/api/v1/sites', ['name' => 'Private Site Name', 'description' => 'Private notes'])->assertCreated()->json('data.id');
        Log::shouldHaveReceived('notice')->with('location.created', [
            'actor_uuid' => $admin->uuid, 'location_uuid' => $id, 'kind' => 'site', 'fields' => ['name', 'description'],
        ])->once();
    }
}
