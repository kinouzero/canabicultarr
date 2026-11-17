<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function hierarchy(): array
    {
        $site = Site::create(['name' => 'Fictional Site', 'description' => 'Private site notes']);
        $room = $site->rooms()->create(['name' => 'Fictional Room', 'description' => 'Private room notes']);
        $zone = $room->zones()->create(['name' => 'Fictional Zone']);

        return [$site, $room, $zone];
    }

    public function test_catalog_bounds_individual_rows_even_with_a_large_single_site_and_has_no_per_row_queries(): void
    {
        [$site, $room] = $this->hierarchy();
        $rows = [];
        for ($index = 0; $index < 2000; $index++) {
            $name = sprintf('Zone %04d', $index);
            $rows[] = ['id' => (string) Str::uuid(), 'room_id' => $room->id, 'code' => 'ZONE-'.$index, 'name' => $name, 'name_key' => Str::lower($name)];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('zones')->insert($chunk);
        }
        $this->actingAs(User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]));
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/locations/options')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 2003)->assertJsonPath('meta.last_page', 101)
            ->assertJsonPath('data.0.context.id', $site->id)->assertJsonPath('data.1.context.id', $room->id)
            ->assertJsonMissingPath('data.0.description')->assertJsonMissingPath('data.0.site_key');
        $smallQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson('/api/v1/locations/options?per_page=100')->assertOk()->assertJsonCount(100, 'data');
        $this->assertLessThanOrEqual($smallQueries + 2, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertLessThan(30, $smallQueries);
        $this->assertSame($site->id, $response->json('data.19.context.site.id'));
        $this->getJson('/api/v1/locations/options?page=2')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonMissing(['id' => $response->json('data.19.id')]);
    }

    public function test_zone_grants_preserve_disabled_ancestor_labels_but_hide_other_branches_and_exact_contexts(): void
    {
        [$site, $room, $zone] = $this->hierarchy();
        $hidden = $room->zones()->create(['name' => 'Hidden sibling']);
        Site::create(['name' => 'Hidden site']);
        $user = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $zone->id]]]);
        $this->actingAs($user)->getJson('/api/v1/locations/options')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.disabled', true)->assertJsonPath('data.1.disabled', true)->assertJsonPath('data.2.disabled', false)
            ->assertJsonPath('data.2.context.site.id', $site->id)->assertJsonPath('data.2.context.room.id', $room->id)
            ->assertJsonPath('data.2.ancestorSelection.site', false)->assertJsonPath('data.2.ancestorSelection.room', false)
            ->assertJsonMissing(['name' => 'Hidden site'])->assertJsonMissing(['id' => 'zone:'.$hidden->id]);
        $this->getJson('/api/v1/locations/options?search='.urlencode('zone:'.strtoupper($zone->id)))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.context.id', $zone->id);
        $this->getJson('/api/v1/locations/options?search='.$hidden->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/locations/zone/'.$zone->id.'/context')->assertOk()->assertJsonPath('data.context.id', $zone->id);
        $this->getJson('/api/v1/locations/site/'.$site->id.'/context')->assertNotFound();
        $this->getJson('/api/v1/locations/zone/'.$hidden->id.'/context')->assertNotFound();
        $this->getJson('/api/v1/locations/room/'.$zone->id.'/context')->assertNotFound();
    }

    public function test_path_search_treats_wildcards_literally_and_exact_lookup_does_not_expand_the_tree(): void
    {
        [$site, $room, $zone] = $this->hierarchy();
        $special = $room->zones()->create(['name' => 'Étage 50%_A']);
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/locations/options?search='.urlencode('Fictional Site / Fictional Room / Étage'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.context.id', $special->id);
        $this->getJson('/api/v1/locations/options?search='.urlencode('%_'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.context.id', $special->id);
        $this->getJson('/api/v1/locations/zone/'.$zone->id.'/context')->assertOk()
            ->assertJsonPath('data.name', 'Fictional Site / Fictional Room / Fictional Zone')->assertJsonMissingPath('data.children');
    }

    public function test_catalog_and_context_require_authentication_and_validate_paging(): void
    {
        [$site] = $this->hierarchy();
        $this->getJson('/api/v1/locations/options')->assertUnauthorized();
        $this->getJson('/api/v1/locations/site/'.$site->id.'/context')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/locations/options?per_page=101&page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page']);
        $this->getJson('/api/v1/locations/site/not-a-uuid/context')->assertNotFound();
    }
}
