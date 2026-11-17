<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_normalize_and_are_unique_only_inside_their_parent(): void
    {
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $site = Site::create(['name' => 'Site one']);
        $other = Site::create(['name' => 'Site two']);
        $url = '/api/v1/sites/'.$site->id.'/rooms';
        $id = $this->postJson($url, ['name' => 'First room', 'code' => ' veg-01 '])->assertCreated()->assertJsonPath('data.code', 'VEG-01')->json('data.id');
        $this->postJson($url, ['name' => 'Second room', 'code' => 'veg-01'])->assertConflict()->assertJsonPath('error.code', 'locations.errors.codeTaken');
        $this->postJson('/api/v1/sites/'.$other->id.'/rooms', ['name' => 'Other room', 'code' => 'VEG-01'])->assertCreated();
        $second = $this->postJson($url, ['name' => 'Third room'])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^R-[A-Z0-9]{12}$/', $second['code']);
        $this->patchJson($url.'/'.$id, ['description' => 'Preserved code'])->assertOk()->assertJsonPath('data.code', 'VEG-01');
        $this->patchJson($url.'/'.$id, ['code' => ' nursery '])->assertOk()->assertJsonPath('data.code', 'NURSERY');
        $zones = $url.'/'.$id.'/zones';
        $this->postJson($zones, ['name' => 'First zone', 'code' => 'A-1'])->assertCreated();
        $this->postJson($zones, ['name' => 'Second zone', 'code' => 'a-1'])->assertConflict();
        $this->postJson($url.'/'.$second['id'].'/zones', ['name' => 'Other zone', 'code' => 'A-1'])->assertCreated();
        $this->getJson($url.'?search=nursery')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/locations/options?search=nursery')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.context.room.code', 'NURSERY');
    }

    public function test_invalid_and_unauthorized_changes_preserve_codes_and_parent_scope(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Allowed site']);
        $room = $site->rooms()->create(['name' => 'Visible room', 'code' => 'ROOM-ONE']);
        $hiddenSite = Site::create(['name' => 'Hidden site']);
        $hidden = $hiddenSite->rooms()->create(['name' => 'Hidden room', 'code' => 'PRIVATE-CODE']);
        $url = '/api/v1/sites/'.$site->id.'/rooms/'.$room->id;
        $this->actingAs($admin);
        foreach (['', ' ', 'bad code', 'é', str_repeat('a', 33), '-bad', null] as $code) {
            $this->patchJson($url, ['code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
            $this->assertSame('ROOM-ONE', $room->fresh()->code);
        }
        $this->postJson('/api/v1/sites', ['name' => 'No site code', 'code' => 'NO'])->assertUnprocessable();
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $this->actingAs($reader)->getJson('/api/v1/locations/options?search=PRIVATE-CODE')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sites/'.$site->id.'/rooms?search=PRIVATE-CODE')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($url, ['code' => 'CHANGED'])->assertForbidden();
        $this->assertSame('PRIVATE-CODE', $hidden->fresh()->code);
        $this->assertSame('ROOM-ONE', $room->fresh()->code);
    }

    public function test_database_prevents_duplicate_codes_even_when_writes_bypass_the_service(): void
    {
        $site = Site::create(['name' => 'Database site']);
        $first = $site->rooms()->create(['name' => 'First', 'code' => 'SHARED']);
        $second = $site->rooms()->create(['name' => 'Second', 'code' => 'OTHER']);
        $this->expectException(QueryException::class);
        DB::table('rooms')->where('id', $second->id)->update(['code' => $first->code]);
    }
}
