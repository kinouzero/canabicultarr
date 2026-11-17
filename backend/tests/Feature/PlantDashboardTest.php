<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetContext;
use App\Models\Document;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Plant;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\DocumentLibrary;
use App\Services\LocationAccess;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\DashboardProfile;
use Tests\TestCase;

class PlantDashboardTest extends TestCase
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
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->site = Site::create(['name' => 'Fictional Dashboard Site', 'description' => 'Private site notes']);
        $this->strain = Strain::create(['name' => 'Fictional Dashboard Strain']);
    }

    private function plant(array $extra = []): Plant
    {
        return app(PlantTracking::class)->create($this->admin, ['name' => 'Fictional Dashboard Plant', 'request_id' => (string) Str::uuid(), 'strain_id' => $this->strain->id, 'location' => ['type' => 'site', 'id' => $this->site->id], ...$extra]);
    }

    public function test_location_tiles_hydrate_one_bounded_page_and_offer_the_remaining_channels(): void
    {
        $asset = $this->placeAsset(new Asset(['name' => 'Many channels']), ['site_id' => $this->site->id]);
        $type = MeasurementType::create(['name' => 'Temperature', 'code' => 'many_tiles', 'nature' => 'temperature']);
        for ($i = 0; $i < 125; $i++) {
            $sensor = new Sensor(['name' => sprintf('Channel %03d', $i), 'channel_key' => 'channel_'.$i]);
            $sensor->measurement_type_id = $type->id;
            $asset->sensors()->save($sensor);
        }
        $retrieved = 0;
        Sensor::retrieved(function () use (&$retrieved) {
            $retrieved++;
        });
        $key = 'site:'.$this->site->id;
        $response = $this->actingAs($this->admin)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&metadata_only=1')->assertOk();
        $this->assertCount(20, $response->json('locationReadings')[$key]);
        $this->assertSame(125, $response->json('locationReadingsMeta')[$key]['total']);
        // Latest-reading scope validation hydrates the same bounded sensor IDs once more.
        $this->assertLessThanOrEqual(40, $retrieved);
        $url = '/api/v1/sites/'.$this->site->id.'/dashboard-tiles?type=site&id='.$this->site->id;
        $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.sensorName', 'Channel 020')->assertJsonPath('meta.total', 125);
        $this->getJson($url.'&page=7')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson($url.'&page=99')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 125);
        $other = Site::create(['name' => 'Other site']);
        $this->getJson('/api/v1/sites/'.$this->site->id.'/dashboard-tiles?type=site&id='.$other->id)->assertNotFound();
    }

    public function test_location_tiles_include_equipped_empty_rooms_and_zones_without_changing_plant_counts(): void
    {
        $this->travelTo(now()->startOfSecond());
        $room = $this->site->rooms()->create(['name' => 'Equipped room']);
        $zone = $room->zones()->create(['name' => 'Equipped zone']);
        $assets = [];
        foreach (['room' => $room, 'zone' => $zone] as $kind => $location) {
            $asset = $this->placeAsset(new Asset(['name' => $kind.' device', 'category' => $kind === 'zone' ? 'socket' : 'sensor']), [$kind.'_id' => $location->id]);
            $type = MeasurementType::create(['name' => $kind.' type', 'code' => 'tile_'.$kind, 'nature' => $kind === 'zone' ? 'binary' : 'temperature']);
            $sensor = new Sensor(['name' => $kind.' channel', 'channel_key' => $kind, 'expected_interval_seconds' => 60]);
            $sensor->measurement_type_id = $type->id;
            $asset->sensors()->save($sensor);
            Measurement::create(['sensor_id' => $sensor->id, 'value' => $kind === 'zone' ? 0 : 22,
                'observed_at' => now(), 'received_at' => now(), 'asset_context_id' => AssetContext::where('asset_id', $asset->id)->latest('effective_at')->value('id')]);
            $assets[$kind] = $asset;
        }
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&metadata_only=1';
        $response = $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonPath('summary.plants', 0)->assertJsonPath('meta.total', 0)->assertJsonPath('room_meta.total', 1)->assertJsonCount(2, 'groups');
        $readings = $response->json('locationReadings');
        $this->assertSame('22.000000000', $readings['room:'.$room->id][0]['value']);
        $this->assertSame('0.000000000', $readings['zone:'.$zone->id][0]['value']);
        $this->assertSame('binary', $readings['zone:'.$zone->id][0]['measurementType']['nature']);
        $this->assertSame('socket', $readings['zone:'.$zone->id][0]['device']['category']);
        $plant = $this->plant(['location' => ['type' => 'zone', 'id' => $zone->id]]);
        $this->getJson($url)->assertOk()->assertJsonPath('summary.plants', 1)->assertJsonPath('meta.total', 1)->assertJsonCount(2, 'groups');
        $this->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $plant->id);
        $restricted = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $zone->id]]]);
        $response = $this->actingAs($restricted)->getJson($url)->assertOk()->assertJsonCount(1, 'groups');
        $this->assertSame([], $response->json('locationReadings')['room:'.$room->id]);
        $this->assertCount(1, $response->json('locationReadings')['zone:'.$zone->id]);
        // Shared hardware outside the user's scope must not leak through a visible assignment.
        $this->actingAs($this->admin);
        $otherSite = Site::create(['name' => 'Other site']);
        $assets['zone']->locations()->create(['site_id' => $otherSite->id]);
        app(LocationAccess::class)->clear();
        $response = $this->actingAs($restricted)->getJson($url)->assertOk();
        $this->assertSame([], $response->json('locationReadings')['zone:'.$zone->id]);
    }

    public function test_location_tiles_exclude_disabled_channels_and_readings_from_before_a_move(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Room']);
        $old = $room->zones()->create(['name' => 'Old zone']);
        $new = $room->zones()->create(['name' => 'New zone']);
        $asset = $this->placeAsset(new Asset(['name' => 'Moved probe']), ['zone_id' => $old->id]);
        $type = MeasurementType::create(['name' => 'Temperature', 'code' => 'tile_moved', 'nature' => 'temperature']);
        $sensor = new Sensor(['name' => 'Probe', 'channel_key' => 'probe']);
        $sensor->measurement_type_id = $type->id;
        $asset->sensors()->save($sensor);
        Measurement::create(['sensor_id' => $sensor->id, 'value' => 20, 'observed_at' => now()->subMinute(), 'received_at' => now(),
            'asset_context_id' => AssetContext::where('asset_id', $asset->id)->value('id')]);
        $this->placeAsset($asset, ['zone_id' => $new->id]);
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&metadata_only=1';
        $response = $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(1, 'groups');
        $reading = $response->json('locationReadings')['zone:'.$new->id][0];
        $this->assertNull($reading['value']);
        $this->assertNull($reading['observedAt']);
        $sensor->update(['is_enabled' => false]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'groups');
    }

    private function image(?Site $site = null): Document
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+c+XcAAAAASUVORK5CYII=');

        return app(DocumentLibrary::class)->upload($this->admin, ['title' => 'Fictional Plant Photo', 'subject' => ['type' => 'site', 'id' => ($site ?? $this->site)->id]], UploadedFile::fake()->createWithContent('plant.png', $bytes));
    }

    public function test_cards_and_records_expose_current_strain_genetics_without_changing_the_plant(): void
    {
        $plant = $this->plant();
        $this->strain->update(['classification' => 'indica', 'genetics' => '70% indica /30% sativa']);
        $this->actingAs($this->admin)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard')
            ->assertOk()->assertJsonPath('data.0.canDelete', true)->assertJsonPath('data.0.strain.classification', 'indica')->assertJsonPath('data.0.strain.genetics', '70% indica /30% sativa');
        $this->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.strain.classification', 'indica');
        $this->strain->update(['classification' => null, 'genetics' => null]);
        $this->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.strain.classification', null)->assertJsonPath('data.strain.genetics', null);
        $this->assertSame($plant->version, $plant->fresh()->version);
    }

    public function test_guests_cannot_read_site_dashboard_or_private_photos(): void
    {
        $plant = $this->plant();
        $this->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard')->assertUnauthorized();
        $this->get('/api/v1/plants/'.$plant->id.'/photo', ['Accept' => 'image/png'])->assertUnauthorized();
    }

    public function test_filtered_headers_hide_empty_equipped_locations_before_pagination(): void
    {
        $type = MeasurementType::create(['name' => 'Temperature', 'code' => 'filtered_headers', 'nature' => 'temperature']);
        for ($index = 0; $index < 11; $index++) {
            $room = $this->site->rooms()->create(['name' => sprintf('Empty room %02d', $index)]);
            $zone = $room->zones()->create(['name' => 'Empty equipped zone']);
            $asset = $this->placeAsset(new Asset(['name' => 'Empty-zone probe']), ['zone_id' => $zone->id]);
            $sensor = new Sensor(['name' => 'Temperature', 'channel_key' => 'temperature']);
            $sensor->measurement_type_id = $type->id;
            $asset->sensors()->save($sensor);
        }
        $room = $this->site->rooms()->create(['name' => 'Matching room']);
        $zone = $room->zones()->create(['name' => 'Matching zone']);
        $active = $this->plant(['location' => ['type' => 'zone', 'id' => $zone->id]]);
        $active->forceFill(['phase' => 'growth', 'phase_due_at' => now()->subDay()])->save();
        $this->plant(['planned' => true, 'location' => ['type' => 'zone', 'id' => $zone->id]]);
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1';
        $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonPath('room_meta.total', 12)->assertJsonPath('room_meta.last_page', 2);
        foreach (['status=active', 'status=planned', 'phase=growth', 'strain_id='.$this->strain->id, 'due_only=1', 'status=all&phase=growth'] as $filter) {
            foreach (['', '&metadata_only=1'] as $mode) {
                $response = $this->getJson($url.'&'.$filter.$mode.'&room_page=2')->assertOk()
                    ->assertJsonPath('room_meta.total', 1)->assertJsonPath('room_meta.current_page', 1)
                    ->assertJsonPath('room_meta.last_page', 1)->assertJsonCount(1, 'groups');
                $this->assertSame(['zone:'.$zone->id], array_keys($response->json('groups')));
                $this->assertSame(1, $response->json('rooms')[$room->id]['total']);
            }
        }
        $this->getJson($url.'&phase=flowering')->assertOk()->assertJsonCount(0, 'groups')
            ->assertJsonPath('meta.total', 0)->assertJsonPath('room_meta.total', 0)->assertJsonPath('room_meta.last_page', 1);
        $this->getJson($url.'&status=all&sort=name_desc')->assertOk()->assertJsonPath('room_meta.total', 12);
    }

    public function test_board_filters_site_and_archives_and_omits_private_detail_fields(): void
    {
        $plant = $this->plant(['notes' => 'Private plant notes']);
        $other = Site::create(['name' => 'Other Site']);
        $this->plant(['location' => ['type' => 'site', 'id' => $other->id]]);
        $archived = $this->plant();
        $archived->phase = 'archived';
        $archived->save();
        $result = $this->actingAs($this->admin)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.plants', 1)->assertJsonPath('summary.strains', 1)->assertJsonPath('data.0.id', $plant->id)->assertJsonPath('data.0.photoUrl', null);
        foreach (['notes', 'devices', 'durationSnapshot', 'creation_hash'] as $field) {
            $this->assertArrayNotHasKey($field, $result->json('data.0'));
        }
        $this->assertStringNotContainsString('Private site notes', $result->getContent());
        $this->assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
    }

    public function test_room_zone_and_site_assignments_are_all_included_and_summary_is_scoped(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Fictional Room']);
        $zone = $room->zones()->create(['name' => 'Fictional Zone']);
        $this->plant();
        $this->plant(['location' => ['type' => 'room', 'id' => $room->id]]);
        $inZone = $this->plant(['location' => ['type' => 'zone', 'id' => $zone->id]]);
        $reader = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $zone->id]]]);
        $this->actingAs($this->admin)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard')->assertJsonPath('summary.plants', 3);
        $this->actingAs($reader)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.plants', 1)->assertJsonPath('data.0.id', $inZone->id);
        $outside = Site::create(['name' => 'Invisible Site']);
        $this->getJson('/api/v1/sites/'.$outside->id.'/plant-dashboard')->assertNotFound();
    }

    public function test_summary_counts_all_visible_plants_while_pages_are_bounded(): void
    {
        for ($i = 0; $i < 23; $i++) {
            $this->plant(['name' => sprintf('Plant %02d', $i), 'started_at' => now()->subDays(5)->toISOString()]);
        }
        $this->actingAs($this->admin)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard')->assertJsonCount(20, 'data')->assertJsonPath('summary.plants', 23)->assertJsonPath('summary.due', 23)->assertJsonPath('meta.total', 23);
        $this->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard?page=2')->assertJsonCount(3, 'data');
    }

    public function test_filters_apply_before_pagination_and_totals_follow_the_selection(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->plant(['name' => sprintf('A Plant %02d', $i)]);
        }
        $otherStrain = Strain::create(['name' => 'Fictional Selected Strain']);
        $due = $this->plant(['name' => 'Z Due', 'strain_id' => $otherStrain->id]);
        $due->forceFill(['phase' => 'growth', 'phase_due_at' => now()->subDay()])->save();
        $future = $this->plant(['name' => 'Z Future', 'strain_id' => $otherStrain->id]);
        $future->forceFill(['phase' => 'growth', 'phase_due_at' => now()->addDay()])->save();
        $curing = $this->plant(['name' => 'Z Curing']);
        $curing->forceFill(['phase' => 'curing', 'phase_due_at' => now()->subDay()])->save();
        $otherStrain->forceFill(['is_archived' => true])->save();
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard';
        $this->actingAs($this->admin)->getJson($url.'?phase=growth&strain_id='.$otherStrain->id)
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.plants', 2)->assertJsonPath('summary.due', 1)
            ->assertJsonPath('summary.strains', 1)->assertJsonCount(2, 'filterOptions.strains');
        $this->getJson($url.'?due_only=1')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.due', 2)
            ->assertJsonFragment(['id' => $due->id])->assertJsonFragment(['id' => $curing->id]);
        $this->getJson($url.'?phase=growth&due_only=1&strain_id='.$otherStrain->id)->assertJsonPath('data.0.id', $due->id);
        $this->getJson($url.'?phase=planned')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.plants', 0)->assertJsonCount(2, 'filterOptions.strains');
        $this->getJson($url.'?sort=name_desc')->assertJsonPath('data.0.id', $future->id)->assertJsonPath('meta.total', 25);
        $this->getJson($url.'?sort=name_desc&page=2')->assertJsonCount(5, 'data')->assertJsonPath('data.0.name', 'A Plant 04');
    }

    public function test_deadline_and_lifecycle_sorts_are_stable_and_unknown_deadlines_stay_last(): void
    {
        $alpha = $this->plant(['name' => 'Alpha']);
        $alpha->forceFill(['phase' => 'flowering', 'phase_due_at' => now()->addDay()])->save();
        $beta = $this->plant(['name' => 'Beta']);
        $beta->forceFill(['phase' => 'growth', 'phase_due_at' => now()->addDays(2)])->save();
        $gamma = $this->plant(['name' => 'Gamma', 'planned' => true]);
        $delta = $this->plant(['name' => 'Delta']);
        $delta->forceFill(['phase' => 'maturation', 'phase_due_at' => null])->save();
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?status=all&sort=';
        $orders = [
            'name_asc' => [$alpha, $beta, $delta, $gamma], 'name_desc' => [$gamma, $delta, $beta, $alpha],
            'phase_asc' => [$gamma, $beta, $alpha, $delta], 'phase_desc' => [$delta, $alpha, $beta, $gamma],
            'due_asc' => [$alpha, $beta, $delta, $gamma], 'due_desc' => [$beta, $alpha, $delta, $gamma],
        ];
        $this->actingAs($this->admin);
        foreach ($orders as $sort => $expected) {
            $actual = $this->getJson($url.$sort)->assertOk()->json('data');
            $this->assertSame(array_map(fn ($plant) => $plant->id, $expected), array_column($actual, 'id'), $sort);
        }
    }

    public function test_filter_options_do_not_reveal_outside_or_archived_only_plants(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Filter Room']);
        $zone = $room->zones()->create(['name' => 'Visible Filter Zone']);
        $this->plant(['location' => ['type' => 'zone', 'id' => $zone->id]]);
        $hiddenStrain = Strain::create(['name' => 'Hidden Filter Strain']);
        $this->plant(['strain_id' => $hiddenStrain->id]);
        $archiveStrain = Strain::create(['name' => 'Archived-only Filter Strain']);
        $archived = $this->plant(['strain_id' => $archiveStrain->id, 'location' => ['type' => 'zone', 'id' => $zone->id]]);
        $archived->forceFill(['phase' => 'archived'])->save();
        $reader = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $zone->id]]]);
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard';
        $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonCount(1, 'filterOptions.strains')
            ->assertJsonPath('filterOptions.strains.0.id', $this->strain->id)->assertJsonPath('summary.plants', 1);
        $this->getJson($url.'?strain_id='.$hiddenStrain->id)->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.plants', 0)->assertJsonCount(1, 'filterOptions.strains');
    }

    public function test_planned_plants_are_hidden_by_default_and_status_switch_preserves_both_totals(): void
    {
        $this->plant(['planned' => true]);
        $this->plant();
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard';
        $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.phase', 'sowing')
            ->assertJsonPath('summary.plants', 1)->assertJsonPath('summary.planned', 1);
        $this->getJson($url.'?status=planned')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.phase', 'planned')
            ->assertJsonPath('summary.plants', 1)->assertJsonPath('summary.planned', 1)->assertJsonPath('summary.due', 0);
        $this->getJson($url.'?status=planned&due_only=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_zone_assignments_precede_unassigned_plants_before_pagination(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Mixed Room']);
        $zone = $room->zones()->create(['name' => 'First Zone']);
        $atSite = $this->plant(['name' => 'A Site']);
        $inRoom = $this->plant(['name' => 'A Room', 'location' => ['type' => 'room', 'id' => $room->id]]);
        $zoneIds = [];
        for ($i = 0; $i < 21; $i++) {
            $zoneIds[] = $this->plant(['name' => sprintf('Z Zone %02d', $i), 'location' => ['type' => 'zone', 'id' => $zone->id]])->id;
        }
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard';
        $first = $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(20, 'data');
        $this->assertSame(array_slice($zoneIds, 0, 20), array_column($first->json('data'), 'id'));
        $last = $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([$zoneIds[20], $inRoom->id, $atSite->id], array_column($last->json('data'), 'id'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_grouped_dashboard_handles_more_than_a_thousand_zones_without_per_group_sql_ranges(): void
    {
        Log::spy();
        $room = $this->site->rooms()->create(['name' => 'Large dashboard room']);
        $template = $this->plant();
        $attributes = $template->getRawOriginal();
        $template->update(['phase' => 'archived']);
        $zones = [];
        $plants = [];
        for ($index = 0; $index < 1100; $index++) {
            $id = (string) Str::uuid();
            $name = sprintf('Large group %04d', $index);
            $zones[] = ['id' => $id, 'room_id' => $room->id, 'code' => 'LARGE-'.$index, 'name' => $name, 'name_key' => Str::lower($name)];
            foreach (['A', 'B'] as $suffix) {
                $plants[] = [...$attributes, 'id' => (string) Str::uuid(), 'creation_key' => (string) Str::uuid(), 'name' => $name.' '.$suffix, 'name_key' => Str::lower($name.' '.$suffix),
                    'site_id' => $this->site->id, 'room_id' => $room->id, 'zone_id' => $id, 'phase' => 'maturation', 'phase_due_at' => null];
            }
        }
        foreach (array_chunk($zones, 100) as $chunk) {
            DB::table('zones')->insert($chunk);
        }
        foreach (array_chunk($plants, 100) as $chunk) {
            DB::table('plants')->insert($chunk);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = ['sql' => $query->sql, 'bindings' => count($query->bindings)];
        });
        $response = $this->actingAs($this->admin)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&per_page=1')
            ->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 2200)->assertJsonPath('summary.plants', 2200);
        $this->assertCount(10, $response->json('groups'));
        $response->assertJsonPath('rooms.'.$room->id.'.last_page', 110);
        $ranking = array_values(array_filter($queries, fn ($query) => str_contains($query['sql'], 'ranked_plants')));
        $this->assertCount(1, $ranking);
        $this->assertLessThan(30, $ranking[0]['bindings']);
        $queryStart = count($queries);
        memory_reset_peak_usage();
        $started = hrtime(true);
        $metadata = $this->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&per_page=1&metadata_only=1')->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(10, 'groups');
        if (getenv('DASHBOARD_HEADER_BENCHMARK')) {
            file_put_contents('/tmp/canabicultarr-header-benchmark.json', json_encode(['headers' => count($metadata->json('groups')), 'bytes' => strlen($metadata->getContent()), 'queries' => count($queries) - $queryStart, 'peak_bytes' => memory_get_peak_usage(true), 'milliseconds' => round((hrtime(true) - $started) / 1e6, 2)]).PHP_EOL);
        }

        $this->assertLessThan(strlen($response->getContent()) / 2, strlen($metadata->getContent()));

        $pages = [];
        $expected = [];
        foreach ($zones as $index => $zone) {
            if ($index < 900) {
                $pages['zone:'.$zone['id']] = 2;
            }
            if ($index < 10) {
                $expected[] = $plants[$index * 2 + 1]['id'];
            }
        }
        $overrides = $this->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard?'.http_build_query(['grouped' => 1, 'per_page' => 1, 'pages' => $pages]))
            ->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 2200);
        $this->assertEqualsCanonicalizing($expected, array_column($overrides->json('data'), 'id'));

        $reader = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $zones[0]['id']]]]);
        $this->actingAs($reader)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $plants[0]['id']);
        DashboardProfile::capture(fn (bool $metadata) => $this->actingAs($this->admin)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&per_page=1'.($metadata ? '&metadata_only=1' : ''))->assertOk()->assertJsonCount($metadata ? 0 : 10, 'data')->assertJsonCount(10, 'groups')->assertJsonPath('summary.plants', 2200));
    }

    public function test_grouped_page_overrides_work_for_site_room_and_zone_groups_together(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Mixed page room']);
        $zone = $room->zones()->create(['name' => 'Mixed page zone']);
        $pages = [];
        $expected = [];
        foreach (['site' => $this->site->id, 'room' => $room->id, 'zone' => $zone->id] as $type => $id) {
            $location = ['type' => $type, 'id' => $id];
            $this->plant(['name' => 'A '.$type, 'location' => $location]);
            $expected[] = $this->plant(['name' => 'B '.$type, 'location' => $location])->id;
            $pages[$type.':'.$id] = 2;
        }
        $response = $this->actingAs($this->admin)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard?'.http_build_query(['grouped' => 1, 'per_page' => 1, 'pages' => $pages]))
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 6);
        $this->assertEqualsCanonicalizing($expected, array_column($response->json('data'), 'id'));
        foreach (array_keys($pages) as $key) {
            $this->assertSame(2, $response->json('groups')[$key]['current_page']);
        }
    }

    public function test_metadata_keeps_global_counts_and_selected_previews_return_scoped_groups_only(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Preview room']);
        $zone = $room->zones()->create(['name' => 'Preview zone']);
        $zonePlant = $this->plant(['location' => ['type' => 'zone', 'id' => $zone->id]]);
        $this->plant(['location' => ['type' => 'room', 'id' => $room->id]]);
        $this->plant();
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&per_page=10';
        $metadata = $this->actingAs($this->admin)->getJson($url.'&metadata_only=1')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('summary.plants', 3);
        $headers = $metadata->json('groups');
        $this->assertCount(3, $headers);
        $this->assertSame('Preview zone', $headers['zone:'.$zone->id]['location']['zone']['name']);
        $this->assertSame('Preview room', $headers['room:'.$room->id]['location']['room']['name']);
        $this->assertSame($this->site->id, $headers['site:'.$this->site->id]['location']['id']);
        $query = '&'.http_build_query(['preview_groups' => ['zone:'.$zone->id]]);
        $this->getJson($url.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $zonePlant->id)->assertJsonPath('meta.total', 1)->assertJsonMissingPath('summary')->assertJsonMissingPath('filterOptions');
        $reader = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $zone->id]]]);
        $this->actingAs($reader)->getJson($url.'&metadata_only=1')->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(1, 'groups')->assertJsonPath('meta.total', 1);
        $this->getJson($url.'&'.http_build_query(['preview_groups' => ['room:'.$room->id]]))->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(0, 'groups');
        $this->getJson($url.'&preview_groups[]=invalid')->assertUnprocessable();
    }

    public function test_summary_combines_lifecycle_and_watering_due_without_duplicates_or_all_due_id_bindings(): void
    {
        $both = $this->plant(['name' => 'Both due']);
        $both->forceFill(['phase' => 'growth', 'started_at' => now()->subDays(5), 'phase_due_at' => now()->subDay()])->save();
        $waterOnly = $this->plant(['name' => 'Water only']);
        $waterOnly->forceFill(['phase' => 'growth', 'started_at' => now()->subDays(5), 'phase_due_at' => now()->addDay()])->save();
        $lifecycleOnly = $this->plant(['name' => 'Lifecycle only']);
        $lifecycleOnly->forceFill(['phase' => 'curing', 'phase_due_at' => now()->subDay()])->save();
        $recent = $this->plant(['name' => 'Recent']);
        $recent->forceFill(['phase' => 'growth', 'started_at' => now()->subDay(), 'phase_due_at' => now()->addDay()])->save();
        $this->plant(['name' => 'Planned', 'planned' => true]);
        $rows = [];
        $attributes = $waterOnly->getRawOriginal();
        for ($i = 0; $i < 600; $i++) {
            $rows[] = [...$attributes, 'id' => (string) Str::uuid(), 'creation_key' => (string) Str::uuid(), 'name' => 'Water '.$i, 'name_key' => 'water '.$i];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('plants')->insert($chunk);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&metadata_only=1';
        $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.plants', 604)->assertJsonPath('summary.planned', 1)->assertJsonPath('summary.due', 603)->assertJsonPath('summary.strains', 1);
        $aggregates = array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'actions_due')));
        DB::disableQueryLog();
        $this->assertCount(1, $aggregates);
        $this->assertLessThan(100, count($aggregates[0]['bindings']));
        $this->assertNotContains($waterOnly->id, $aggregates[0]['bindings']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($url.'&due_only=1')->assertOk()->assertJsonPath('meta.total', 603)
            ->assertJsonPath('summary.plants', 603)->assertJsonPath('summary.planned', 0)->assertJsonPath('summary.due', 603);
        $dueAggregates = array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'actions_due')));
        DB::disableQueryLog();
        $this->assertCount(1, $dueAggregates);
        $this->assertLessThan(100, count($dueAggregates[0]['bindings']));
        $this->assertNotContains($waterOnly->id, $dueAggregates[0]['bindings']);
        $this->getJson($url.'&status=planned')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.plants', 604)->assertJsonPath('summary.planned', 1)->assertJsonPath('summary.due', 603);
    }

    public function test_preview_watering_work_stays_in_requested_groups_and_due_filter_still_applies(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Workload room']);
        $zone = $room->zones()->create(['name' => 'Requested zone']);
        $other = $room->zones()->create(['name' => 'Unrequested zone']);
        $dry = $this->plant(['name' => 'Dry plant', 'location' => ['type' => 'zone', 'id' => $zone->id]]);
        $dry->forceFill(['phase' => 'growth', 'started_at' => now()->subDays(4)])->save();
        $wet = $this->plant(['name' => 'Recent plant', 'location' => ['type' => 'zone', 'id' => $zone->id]]);
        $wet->forceFill(['phase' => 'growth', 'started_at' => now()->subDay()])->save();
        $attributes = $dry->getRawOriginal();
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = [...$attributes, 'id' => (string) Str::uuid(), 'creation_key' => (string) Str::uuid(), 'name' => 'Other '.$i, 'name_key' => 'other '.$i, 'zone_id' => $other->id];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('plants')->insert($chunk);
        }
        $loaded = [];
        Plant::retrieved(function (Plant $plant) use (&$loaded) {
            if ($plant->name !== null) {
                $loaded[] = $plant->id;
            }
        });
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?'.http_build_query(['grouped' => 1, 'per_page' => 10, 'preview_groups' => ['zone:'.$zone->id]]);
        $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)->assertJsonMissingPath('summary');
        $this->assertCount(2, $loaded);
        $this->assertEqualsCanonicalizing([$dry->id, $wet->id], array_unique($loaded));
        $loaded = [];
        $this->getJson($url.'&due_only=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $dry->id)->assertJsonPath('meta.total', 1);
        $this->assertSame([$dry->id], $loaded);
        $this->assertCount(1, $loaded);
    }

    public function test_grouped_cards_do_not_add_queries_per_zone(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Synthetic query count room']);
        $addZone = function (int $index) use ($room) {
            $zone = $room->zones()->create(['name' => 'Synthetic query count zone '.$index]);
            $this->plant(['location' => ['type' => 'zone', 'id' => $zone->id]]);
        };
        $addZone(0);
        $reads = 0;
        $recording = false;
        DB::listen(function () use (&$reads, &$recording) {
            if ($recording) {
                $reads++;
            }
        });
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&per_page=10';
        $this->actingAs(User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]], 'permission_grants' => ['plants.manage']]));
        $recording = true;
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $baseline = $reads;
        $recording = false;
        for ($i = 1; $i < 8; $i++) {
            $addZone($i);
        }
        $reads = 0;
        $recording = true;
        $this->getJson($url)->assertOk()->assertJsonCount(8, 'data');
        $recording = false;
        $this->assertLessThanOrEqual($baseline + 2, $reads);
    }

    public function test_header_pages_bound_rooms_and_independent_zones_and_preserve_global_scope(): void
    {
        $template = $this->plant();
        $attributes = $template->getRawOriginal();
        $template->update(['phase' => 'archived']);
        $rooms = [];
        $zones = [];
        $plants = [];
        for ($r = 0; $r < 12; $r++) {
            $room = $this->site->rooms()->create(['name' => sprintf('Room %02d', $r)]);
            $rooms[] = $room;
            for ($z = 0; $z < 12; $z++) {
                $id = (string) Str::uuid();
                $zones[$r][] = ['id' => $id, 'room_id' => $room->id, 'code' => sprintf('ZONE-%02d', $z), 'name' => sprintf('Zone %02d', $z), 'name_key' => sprintf('zone %02d', $z)];
                $plants[] = [...$attributes, 'id' => (string) Str::uuid(), 'creation_key' => (string) Str::uuid(), 'room_id' => $room->id, 'zone_id' => $id, 'phase' => 'maturation', 'phase_due_at' => null];
            }
        }
        foreach ($zones as $rows) {
            DB::table('zones')->insert($rows);
        }
        foreach (array_chunk($plants, 50) as $rows) {
            DB::table('plants')->insert($rows);
        }
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1&metadata_only=1';
        $first = $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(100, 'groups')->assertJsonCount(10, 'rooms')
            ->assertJsonPath('room_meta.total', 12)->assertJsonPath('room_meta.last_page', 2)->assertJsonPath('summary.plants', 144)->assertJsonPath('meta.total', 144);
        $next = $this->getJson($url.'&'.http_build_query(['zone_pages' => [$rooms[0]->id => 2]]))->assertOk()->assertJsonCount(92, 'groups');
        $this->assertArrayHasKey('zone:'.$zones[0][10]['id'], $next->json('groups'));
        $this->assertArrayNotHasKey('zone:'.$zones[0][0]['id'], $next->json('groups'));
        $this->assertSame(1, $next->json('rooms')[$rooms[1]->id]['current_page']);
        $clamped = $this->getJson($url.'&'.http_build_query(['room_page' => 999, 'zone_pages' => [$rooms[11]->id => 999]]))->assertOk()->assertJsonCount(12, 'groups')->assertJsonPath('room_meta.current_page', 2);
        $this->assertSame(2, $clamped->json('rooms')[$rooms[11]->id]['current_page']);
        $reader = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $zones[0][0]['id']], ['type' => 'zone', 'id' => $zones[0][11]['id']]]]);
        $this->actingAs($reader)->getJson($url.'&'.http_build_query(['zone_pages' => [$rooms[0]->id => 999, $rooms[1]->id => 2]]))->assertOk()
            ->assertJsonCount(2, 'groups')->assertJsonCount(1, 'rooms')->assertJsonPath('room_meta.total', 1)->assertJsonPath('meta.total', 2)->assertJsonPath('summary.plants', 2);
        $this->getJson($url.'&zone_pages[x]=invalid')->assertUnprocessable();
        $this->getJson($url.'&room_page=0')->assertUnprocessable();
    }

    public function test_group_pages_are_independent_clamped_and_geographically_scoped(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Paged Room']);
        $a = $room->zones()->create(['name' => 'Zone A']);
        $b = $room->zones()->create(['name' => 'Zone B']);
        foreach ([$a, $b] as $zone) {
            for ($i = 0; $i < 21; $i++) {
                $this->plant(['name' => sprintf('Plant %02d', $i), 'location' => ['type' => 'zone', 'id' => $zone->id]]);
            }
        }
        $this->plant();
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?grouped=1';
        $first = $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(41, 'data')->assertJsonPath('meta.total', 43);
        $small = $this->getJson($url.'&per_page=10')->assertOk()->assertJsonCount(21, 'data');
        $this->assertSame(10, $small->json('groups')['zone:'.$a->id]['per_page']);
        $this->assertSame(3, $small->json('groups')['zone:'.$a->id]['last_page']);
        $this->getJson($url.'&per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->assertSame(21, $first->json('groups')['zone:'.$a->id]['total']);
        $next = $this->getJson($url.'&'.http_build_query(['pages' => ['zone:'.$a->id => 2]]))->assertOk()->assertJsonCount(22, 'data');
        $this->assertSame(2, $next->json('groups')['zone:'.$a->id]['current_page']);
        $this->assertSame(1, $next->json('groups')['zone:'.$b->id]['current_page']);
        $clamped = $this->getJson($url.'&'.http_build_query(['pages' => ['zone:'.$a->id => 99]]))->assertOk()->assertJsonCount(22, 'data');
        $this->assertSame(2, $clamped->json('groups')['zone:'.$a->id]['current_page']);
        $reader = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $b->id]]]);
        $scoped = $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21);
        $this->assertSame(['zone:'.$b->id], array_keys($scoped->json('groups')));
        $this->getJson($url.'&pages[invalid]=0')->assertUnprocessable();
    }

    public function test_complete_gallery_lists_match_the_exact_location_and_keep_access_scopes(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Gallery room']);
        $one = $room->zones()->create(['name' => 'Gallery zone one']);
        $two = $room->zones()->create(['name' => 'Gallery zone two']);
        $plants = [];
        foreach ([['site', $this->site->id], ['room', $room->id], ['zone', $one->id], ['zone', $two->id]] as [$type, $id]) {
            $plant = $this->plant(['name' => 'Location '.$type.' '.$id, 'location' => ['type' => $type, 'id' => $id]]);
            $plants[] = [$type, $id, $plant];
        }
        foreach ($plants as [$type, $id, $plant]) {
            $url = '/api/v1/plants?status=all&location_type='.$type.'&location_id='.$id;
            $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $plant->id);
        }
        $reader = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $one->id]]]);
        $this->actingAs($reader)->getJson('/api/v1/plants?status=all&location_type=zone&location_id='.$two->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/plants?status=all&location_type=zone&location_id='.$one->id)->assertOk()->assertJsonCount(1, 'data');
        foreach (['location_type=unknown&location_id='.$one->id, 'location_type=zone', 'location_id='.$one->id, 'location_type=zone&location_id=invalid'] as $query) {
            $this->getJson('/api/v1/plants?'.$query)->assertUnprocessable();
        }
    }

    public function test_dashboard_rejects_invalid_filter_and_sort_values(): void
    {
        $this->actingAs($this->admin);
        $url = '/api/v1/sites/'.$this->site->id.'/plant-dashboard?';
        foreach (['status=invalid', 'phase=archived', 'sort=phase%3BDROP', 'strain_id=not-a-uuid', 'due_only=yes', 'page=0'] as $query) {
            $this->getJson($url.$query)->assertUnprocessable();
        }
        $this->getJson($url.'strain_id='.Str::uuid())->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_primary_photo_is_private_inline_and_requires_both_access_contexts(): void
    {
        $plant = $this->plant();
        $photo = $this->image();
        $this->actingAs($this->admin)->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'photo_document_id' => $photo->id])->assertOk()->assertJsonPath('data.version', 2);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($reader)->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard')->assertOk()->assertJsonPath('data.0.photoUrl', '/api/v1/plants/'.$plant->id.'/photo?version=2');
        $response = $this->get('/api/v1/plants/'.$plant->id.'/photo')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Content-Disposition', 'inline')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('documents')->get($photo->path), $response->streamedContent());
        $outside = Site::create(['name' => 'Outside Photo Context']);
        $photo->site_id = $outside->id;
        $photo->subject_snapshot = ['type' => 'site', 'id' => $outside->id, 'location' => ['site' => ['id' => $outside->id, 'name' => $outside->name]]];
        $photo->save();
        $this->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard')->assertOk()->assertJsonPath('data.0.photoUrl', null);
        $this->get('/api/v1/plants/'.$plant->id.'/photo')->assertNotFound();
    }

    public function test_archived_missing_or_non_image_documents_cannot_be_primary_photos(): void
    {
        $plant = $this->plant();
        $photo = $this->image();
        $photo->is_archived = true;
        $photo->save();
        $this->actingAs($this->admin)->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'photo_document_id' => $photo->id])->assertUnprocessable();
        $photo->is_archived = false;
        $photo->format = 'pdf';
        $photo->save();
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'photo_document_id' => $photo->id])->assertUnprocessable();
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'photo_document_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(1, $plant->fresh()->version);
        $this->assertSame(1, $plant->events()->count());
    }

    public function test_photo_link_changes_are_versioned_and_can_be_cleared_without_deleting_the_document(): void
    {
        $plant = $this->plant();
        $photo = $this->image();
        $this->actingAs($this->admin)->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'photo_document_id' => $photo->id])->assertOk();
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'photo_document_id' => null])->assertConflict();
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 2, 'photo_document_id' => null])->assertOk()->assertJsonPath('data.photoUrl', null);
        $this->assertDatabaseHas('documents', ['id' => $photo->id]);
        $this->assertTrue(Storage::disk('documents')->exists($photo->path));
        $this->assertSame(3, $plant->events()->count());
        $this->get('/api/v1/plants/'.$plant->id.'/photo')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_archiving_a_linked_photo_hides_it_without_changing_the_plant(): void
    {
        $plant = $this->plant();
        $photo = $this->image();
        $plant = app(PlantTracking::class)->update($this->admin, $plant, ['version' => 1, 'photo_document_id' => $photo->id]);
        $photo->is_archived = true;
        $photo->save();
        $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.photoUrl', null)->assertJsonPath('data.version', 2);
        $this->get('/api/v1/plants/'.$plant->id.'/photo')->assertNotFound();
    }
}
