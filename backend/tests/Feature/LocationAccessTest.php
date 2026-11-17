<?php

namespace Tests\Feature;

use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\Document;
use App\Models\HomeAssistantLink;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\NotificationDelivery;
use App\Models\Plant;
use App\Models\Room;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\Zone;
use App\Services\AlertEvaluator;
use App\Services\AssetAdministration;
use App\Services\DocumentLibrary;
use App\Services\EventJournal;
use App\Services\HttpSourceAdministration;
use App\Services\Inventory;
use App\Services\LocationAccess;
use App\Services\NotificationAdministration;
use App\Services\NotificationConfiguration;
use App\Services\NotificationDispatcher;
use App\Services\SourceDiagnostics;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationAccessTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    private Site $siteA;

    private Site $siteB;

    private Room $roomA;

    private Room $roomOther;

    private Zone $zoneA;

    private Zone $zoneOther;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('documents');
        $this->travelTo(now()->startOfSecond());
        config(['mqtt.enabled' => true, 'mqtt.host' => 'fictional-broker', 'mqtt.port' => 1883, 'mqtt.tls' => false]);
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->siteA = Site::create(['name' => 'Allowed Fictional Site', 'description' => 'Private parent notes']);
        $this->siteB = Site::create(['name' => 'Hidden Fictional Site']);
        $this->roomA = $this->siteA->rooms()->create(['name' => 'Allowed Fictional Room', 'description' => 'Private room notes']);
        $this->roomOther = $this->siteA->rooms()->create(['name' => 'Hidden Fictional Room']);
        $this->zoneA = $this->roomA->zones()->create(['name' => 'Allowed Fictional Zone']);
        $this->zoneOther = $this->roomA->zones()->create(['name' => 'Hidden Fictional Zone']);
    }

    protected function tearDown(): void
    {
        Auth::logout();
        DB::table('asset_assignments')->delete();
        Measurement::whereNotNull('mqtt_measurement_source_id')->orWhereNotNull('http_measurement_source_id')->delete();
        DB::table('inventory_movements')->delete();
        DB::table('inventory_balances')->delete();
        DB::table('storage_locations')->delete();
        parent::tearDown();
    }

    private function member(?array $locations = null, array $grants = []): User
    {
        return User::factory()->create(['location_access' => $locations, 'permission_grants' => $grants]);
    }

    private function allowed(): array
    {
        return [['type' => 'zone', 'id' => $this->zoneA->id]];
    }

    private function location(string $kind = 'zone'): array
    {
        return ['type' => $kind, 'id' => match ($kind) {
            'site' => $this->siteA->id, 'room' => $this->roomA->id, default => $this->zoneA->id
        }];
    }

    private function asset(?array $location, string $name = 'Fictional Scoped Device'): Asset
    {
        return app(AssetAdministration::class)->save($this->admin, ['name' => $name, 'locations' => $location === null ? [] : [$location]]);
    }

    private function sensor(Asset $asset): Sensor
    {
        $type = MeasurementType::create(['name' => 'Fictional Scoped Type', 'code' => 'scope_'.bin2hex(random_bytes(5)), 'unit' => 'test']);
        $sensor = new Sensor(['name' => $asset->name.' Probe', 'channel_key' => 'probe', 'expected_interval_seconds' => 60]);
        $sensor->asset_id = $asset->id;
        $sensor->measurement_type_id = $type->id;
        $sensor->save();

        return $sensor;
    }

    private function reading(Sensor $sensor, ?array $location, string $value = '12.123456789', int $minutes = 1): void
    {
        $link = HomeAssistantLink::firstOrCreate(['sensor_id' => $sensor->id], ['instance_key' => hash('sha256', 'fictional'), 'entity_id' => 'sensor.'.str_replace('-', '', $sensor->id)]);
        Measurement::create(['sensor_id' => $sensor->id, 'home_assistant_link_id' => $link->id, 'value' => $value, 'unit' => 'test', 'observed_at' => now()->subMinutes($minutes), 'received_at' => now(), 'location_snapshot' => $location]);
    }

    private function userInput(User $user, array $extra = []): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'permission_role_ids' => $user->permission_role_ids, 'is_active' => $user->is_active, ...$extra];
    }

    public function test_scope_compilation_does_not_fetch_descendants_or_grow_bindings_with_the_tree(): void
    {
        $access = app(LocationAccess::class);
        $user = $this->member([['type' => 'site', 'id' => $this->siteA->id]]);
        $user->isAdministrator();
        $compile = fn () => $access->run($user, function () use ($access, $user) {
            $queries = array_map(fn ($model) => $model::query()->select('id')->toBase(), [Plant::class, Site::class, Room::class, Zone::class]);
            $snapshot = DB::table('measurements');
            $access->snapshot($snapshot, 'location_snapshot', $user);
            $queries[] = $snapshot;

            return array_map(fn ($query) => [strlen($query->toSql()), count($query->getBindings())], $queries);
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $before = $compile();
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $zones = [];
        for ($index = 0; $index < 1100; $index++) {
            $zones[] = ['id' => (string) Str::uuid(), 'room_id' => $this->roomA->id, 'code' => 'SCOPE-'.$index, 'name' => 'Scope Zone '.$index, 'name_key' => 'scope zone '.$index, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($zones, 100) as $chunk) {
            DB::table('zones')->insert($chunk);
        }
        $access->clear();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame($before, $compile());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $access->run($user, function () {
            $this->assertSame(1102, Zone::count());
            $this->assertSame(1, Site::count());
            $this->assertSame(2, Room::count());
        });
        $this->assertTrue($access->allows($user, ['type' => 'zone', 'id' => $zones[1099]['id']]));
        $this->assertFalse($access->allows($user, ['type' => 'site', 'id' => $this->siteB->id]));
    }

    public function test_explicit_grants_preserve_deleted_location_snapshot_access(): void
    {
        $id = (string) Str::uuid();
        $user = $this->member([['type' => 'zone', 'id' => $id]]);
        $access = app(LocationAccess::class);
        $sensor = $this->sensor($this->asset($this->location()));
        $this->reading($sensor, ['zone' => ['id' => $id]]);
        $query = DB::table('measurements');
        $access->snapshot($query, 'location_snapshot', $user);
        $this->assertTrue($query->exists());
        $this->assertTrue($access->allows($user, ['type' => 'zone', 'id' => $id]));
        $this->assertFalse($access->allows($user, ['type' => 'zone', 'id' => (string) Str::uuid()]));
    }

    public function test_point_checks_fetch_only_consulted_locations_and_reuse_batched_decisions(): void
    {
        $rows = [];
        for ($i = 0; $i < 2000; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'room_id' => $this->roomA->id, 'code' => 'LOOKUP-'.$i, 'name' => 'Lookup '.$i, 'name_key' => 'lookup '.$i];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('zones')->insert($chunk);
        }
        $references = array_column(array_slice($rows, 0, 3), 'id');
        $records = Zone::withoutGlobalScopes()->whereIn('id', $references)->get();
        $user = $this->member([['type' => 'room', 'id' => $this->roomA->id]]);
        $user->isAdministrator();
        $access = app(LocationAccess::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $access->prepare($user, $records);
        $queries = DB::getQueryLog();
        $this->assertCount(1, $queries);
        foreach ($references as $id) {
            $this->assertContains($id, $queries[0]['bindings']);
            $this->assertTrue($access->allows($user, ['type' => 'zone', 'id' => $id]));
        }
        $this->assertLessThan(10, count($queries[0]['bindings']));
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertFalse($access->allows($user, ['type' => 'zone', 'id' => 'not-a-uuid']));
        $this->assertFalse($access->allows($user, ['type' => 'site', 'id' => $this->siteA->id]));
        DB::table('zones')->where('id', $references[0])->update(['room_id' => $this->roomOther->id]);
        $access->clear();
        $this->assertFalse($access->allows($user, ['type' => 'zone', 'id' => $references[0]]));
    }

    public function test_preparing_snapshot_decisions_does_not_trust_captured_ancestor_ids(): void
    {
        $outside = $this->roomOther->zones()->create(['name' => 'Outside snapshot zone']);
        $user = $this->member([['type' => 'room', 'id' => $this->roomA->id]]);
        $document = new Document;
        $document->subject_snapshot = ['location' => ['type' => 'zone', 'id' => $outside->id, 'site' => ['id' => $this->siteA->id], 'room' => ['id' => $this->roomA->id], 'zone' => ['id' => $outside->id]]];
        $access = app(LocationAccess::class);
        $access->prepare($user, [$document]);
        $this->assertFalse($access->record($user, $document));
        $this->assertTrue($access->allows($user, $this->location()));
    }

    public function test_unrestricted_accounts_keep_existing_access_and_administrators_always_have_all_access(): void
    {
        $this->asset(null);
        $this->asset($this->location());
        $this->actingAs($this->member())->getJson('/api/v1/assets')->assertOk()->assertJsonCount(2, 'data');
        $this->admin->location_access = [];
        $this->admin->save();
        $this->actingAs($this->admin)->getJson('/api/v1/sites')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_empty_location_selection_restricts_all_location_bound_records(): void
    {
        $sensor = $this->sensor($this->asset($this->location()));
        $this->actingAs($this->member([]));
        foreach (['sites', 'assets', 'sensors', 'events', 'documents', 'alert-rules', 'alerts'] as $endpoint) {
            $this->getJson('/api/v1/'.$endpoint)->assertOk()->assertJsonCount(0, 'data');
        }
        $this->getJson('/api/v1/sensors/'.$sensor->id)->assertNotFound();
    }

    public function test_zone_access_exposes_only_navigation_ancestors_and_hides_their_notes_and_siblings(): void
    {
        $this->actingAs($this->member($this->allowed(), ['locations.manage']));
        $this->getJson('/api/v1/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', null)->assertJsonPath('data.0.canSelect', false)->assertJsonPath('data.0.canManage', false)->assertJsonPath('data.0.childrenCount', 1);
        $this->getJson('/api/v1/sites/'.$this->siteA->id.'/rooms')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', null)->assertJsonPath('data.0.childrenCount', 1);
        $this->getJson('/api/v1/sites/'.$this->siteA->id.'/rooms/'.$this->roomA->id.'/zones')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->zoneA->id)->assertJsonPath('data.0.canSelect', true);
        $this->getJson('/api/v1/sites/'.$this->siteB->id.'/rooms')->assertNotFound();
    }

    public function test_site_and_room_grants_inherit_descendants_without_granting_unassigned_or_parent_records(): void
    {
        $this->asset($this->location('site'), 'Site Device');
        $this->asset($this->location('room'), 'Room Device');
        $this->asset($this->location(), 'Zone Device');
        $this->asset(null, 'Unassigned Device');
        $this->actingAs($this->member([$this->location('site')]))->getJson('/api/v1/assets')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($this->member([$this->location('room')]))->getJson('/api/v1/assets')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->member($this->allowed()))->getJson('/api/v1/assets')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_guessed_asset_sensor_source_and_report_urls_cannot_escape_the_scope(): void
    {
        $sensor = $this->sensor($this->asset(['type' => 'site', 'id' => $this->siteB->id]));
        $source = app(HttpSourceAdministration::class)->apply($this->admin, ['sensor_id' => $sensor->id, 'expires_days' => 90])[0];
        $this->actingAs($this->member($this->allowed(), ['assets.manage', 'sensors.manage', 'integrations.manage']));
        foreach (['assets/'.$sensor->asset_id.'/assignments', 'sensors/'.$sensor->id, 'sensors/'.$sensor->id.'/measurements', 'reports/sensors/'.$sensor->id, 'reports/sensors/'.$sensor->id.'/export'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertNotFound();
        }
        $this->patchJson('/api/v1/integrations/http/sources/'.$source->id, ['version' => 1, 'is_enabled' => false])->assertNotFound();
        $this->getJson('/api/v1/integrations/http/sources')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_functional_permissions_remain_required_inside_the_location_scope(): void
    {
        $asset = $this->asset($this->location());
        $this->actingAs($this->member($this->allowed()))->patchJson('/api/v1/assets/'.$asset->id, ['name' => 'Denied Rename', 'status' => 'active'])->assertForbidden();
        $this->actingAs($this->member($this->allowed(), ['assets.manage']))->patchJson('/api/v1/assets/'.$asset->id, ['name' => 'Allowed Rename', 'status' => 'active'])->assertOk();
    }

    public function test_location_managers_can_edit_selected_nodes_but_not_navigation_ancestors_or_global_sites(): void
    {
        $this->actingAs($this->member($this->allowed(), ['locations.manage']));
        $base = '/api/v1/sites/'.$this->siteA->id;
        $this->patchJson($base, ['name' => 'Forbidden'])->assertForbidden();
        $this->postJson('/api/v1/sites', ['name' => 'Forbidden'])->assertForbidden();
        $this->postJson($base.'/rooms', ['name' => 'Forbidden'])->assertForbidden();
        $this->patchJson($base.'/rooms/'.$this->roomA->id.'/zones/'.$this->zoneA->id, ['name' => 'Allowed Zone Rename'])->assertOk();
    }

    public function test_restricted_asset_creation_and_moves_require_an_accessible_destination(): void
    {
        $asset = $this->asset($this->location());
        $this->actingAs($this->member($this->allowed(), ['assets.manage']));
        $this->postJson('/api/v1/assets', ['name' => 'Forbidden Unassigned'])->assertForbidden();
        $this->postJson('/api/v1/assets', ['name' => 'Allowed New', 'locations' => [$this->location()]])->assertCreated();
        $this->patchJson('/api/v1/assets/'.$asset->id, ['name' => $asset->name, 'status' => 'active', 'locations' => []])->assertForbidden();
        $this->patchJson('/api/v1/assets/'.$asset->id, ['name' => $asset->name, 'status' => 'active', 'locations' => [$this->location('site')]])->assertForbidden();
    }

    public function test_assignment_history_hides_previous_outside_assignments_after_an_asset_move(): void
    {
        $asset = $this->asset(['type' => 'site', 'id' => $this->siteB->id]);
        app(AssetAdministration::class)->save($this->admin, ['name' => $asset->name, 'locations' => [$this->location()]], $asset);
        $this->actingAs($this->member($this->allowed()))->getJson('/api/v1/assets/'.$asset->id.'/assignments')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['name' => $this->siteB->name]);
    }

    public function test_reading_history_summary_reports_and_csv_filter_captured_locations(): void
    {
        $sensor = $this->sensor($this->asset($this->location()));
        $outside = $this->asset(['type' => 'site', 'id' => $this->siteB->id], 'Outside Fixture')->locationDetails();
        $this->reading($sensor, $outside, '999.000000001', 1);
        $this->reading($sensor, null, '888.000000001', 2);
        $this->reading($sensor, $sensor->device->locationDetails(), '12.123456789', 3);
        $this->actingAs($this->member($this->allowed()));
        $this->getJson('/api/v1/sensors/'.$sensor->id.'/measurements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.latest.value', '12.123456789');
        $report = $this->getJson('/api/v1/reports/sensors/'.$sensor->id)->assertOk();
        $this->assertStringNotContainsString('999.000000001', $report->getContent());
        $this->assertStringNotContainsString('888.000000001', $report->getContent());
        $csv = $this->get('/api/v1/reports/sensors/'.$sensor->id.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('12.123456789', $csv);
        $this->assertStringNotContainsString('999.000000001', $csv);
        $this->assertStringNotContainsString($this->siteB->name, $csv);
    }

    public function test_event_lists_revisions_and_subject_updates_preserve_location_boundaries(): void
    {
        $entry = app(EventJournal::class)->save($this->admin, ['title' => 'Old Outside Title', 'body' => 'Outside private text', 'category' => 'observation', 'severity' => 'info', 'occurred_at' => now()->toISOString(), 'subject' => ['type' => 'site', 'id' => $this->siteB->id]]);
        $entry = app(EventJournal::class)->save($this->admin, ['version' => 1, 'title' => 'Inside Title', 'body' => 'Inside text', 'subject' => $this->location()], $entry);
        $user = $this->member($this->allowed());
        $entry->created_by = $user->id;
        $entry->save();
        $this->actingAs($user)->getJson('/api/v1/events')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/events/'.$entry->id.'/revisions')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['title' => 'Old Outside Title']);
        $this->patchJson('/api/v1/events/'.$entry->id, ['version' => 2, 'title' => 'Inside Title', 'body' => 'Text', 'category' => 'observation', 'severity' => 'info', 'occurred_at' => now()->toISOString(), 'subject' => null])->assertForbidden();
    }

    public function test_documents_and_downloads_are_scoped_and_unlocated_uploads_are_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('fixture.txt', 'Synthetic attachment');
        $outside = app(DocumentLibrary::class)->upload($this->admin, ['title' => 'Outside Attachment', 'subject' => ['type' => 'site', 'id' => $this->siteB->id]], $file);
        $this->actingAs($this->member($this->allowed()));
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/documents/'.$outside->id)->assertNotFound();
        $this->get('/api/v1/documents/'.$outside->id.'/download')->assertNotFound();
        $this->post('/api/v1/documents', ['title' => 'Unlocated', 'file' => UploadedFile::fake()->createWithContent('fixture.txt', 'Synthetic attachment')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_source_diagnostics_and_dashboard_exclude_outside_sources_and_readings(): void
    {
        $inside = $this->sensor($this->asset($this->location()));
        $outside = $this->sensor($this->asset(['type' => 'site', 'id' => $this->siteB->id], 'Outside Device'));
        $this->reading($inside, null);
        $this->reading($outside, $outside->device->locationDetails());
        $user = $this->member($this->allowed(), ['integrations.manage']);
        $this->actingAs($user);
        $this->getJson('/api/v1/integrations/sources')->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('summary.readings.missing', 1)->assertJsonCount(1, 'data')->assertJsonMissing(['sensorName' => $outside->name]);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.assets.total', 1)->assertJsonPath('data.sensors.withReadings', 0);
        Auth::logout();
        $this->assertSame(1, app(SourceDiagnostics::class)->read($user, [])['summary']['total']);
    }

    public function test_external_discovery_and_global_catalog_edits_are_denied_to_scoped_managers(): void
    {
        $user = $this->member($this->allowed(), ['integrations.manage', 'sensors.manage', 'inventory.manage']);
        $user->forceFill(['inventory_location_access' => []])->save();
        $this->actingAs($user);
        $this->getJson('/api/v1/integrations/home-assistant/entities')->assertForbidden();
        $this->postJson('/api/v1/integrations/home-assistant/check')->assertForbidden();
        $this->postJson('/api/v1/measurement-types', ['code' => 'forbidden', 'name' => 'Forbidden Type'])->assertForbidden();
        $this->postJson('/api/v1/inventory/items', ['name' => 'Forbidden Item', 'unit' => 'piece'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_inventory_balances_totals_movements_and_transfers_are_scoped(): void
    {
        $first = StorageLocation::create(['name' => 'Allowed warehouse']);
        $second = StorageLocation::create(['name' => 'Private warehouse']);
        $item = app(Inventory::class)->save($this->admin, ['name' => 'Shared Catalog Item', 'unit' => 'piece', 'threshold' => '50']);
        $move = fn ($version, $destination, $quantity) => app(Inventory::class)->record($this->admin, $item, ['version' => $version, 'request_id' => (string) Str::uuid(), 'kind' => 'receipt', 'quantity' => $quantity, 'reason' => 'Synthetic receipt', 'destination' => $destination]);
        $move(1, ['id' => $first->id], '2');
        $move(2, ['id' => $second->id], '8');
        $member = $this->member($this->allowed(), ['inventory.manage']);
        $member->forceFill(['inventory_location_access' => [$first->id]])->save();
        $this->actingAs($member);
        $this->getJson('/api/v1/inventory/items/'.$item->id)->assertOk()->assertJsonPath('data.totalQuantity', '2.000')->assertJsonPath('data.threshold', null)->assertJsonPath('data.canEditCatalog', false);
        $this->getJson('/api/v1/inventory/items/'.$item->id.'/balances')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/items/'.$item->id.'/movements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.totalAfter', null);
        $this->postJson('/api/v1/inventory/items/'.$item->id.'/movements', ['version' => 3, 'request_id' => (string) Str::uuid(), 'kind' => 'transfer', 'quantity' => '1', 'reason' => 'Denied transfer', 'source' => ['id' => $first->id], 'destination' => ['id' => $second->id]])->assertNotFound();
        $this->postJson('/api/v1/inventory/items/'.$item->id.'/movements', ['version' => 3, 'request_id' => (string) Str::uuid(), 'kind' => 'receipt', 'quantity' => '1', 'reason' => 'Allowed receipt', 'destination' => ['id' => $first->id]])->assertCreated();
        $this->getJson('/api/v1/inventory/items/'.$item->id)->assertOk()->assertJsonPath('data.totalQuantity', '3.000');
    }

    public function test_alert_episodes_use_captured_location_and_legacy_unknown_context_is_hidden(): void
    {
        $sensor = $this->sensor($this->asset($this->location()));
        $rule = AlertRule::create(['sensor_id' => $sensor->id, 'name' => 'Fictional Scoped Rule', 'kind' => 'missing', 'severity' => 'warning', 'delay_seconds' => 120, 'activated_at' => now()->subHour()]);
        app(AlertEvaluator::class)->evaluate($rule);
        $episode = AlertEpisode::sole();
        $this->actingAs($this->member($this->allowed()))->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(1, 'data');
        Auth::logout();
        $snapshot = $episode->rule_snapshot;
        unset($snapshot['location'], $snapshot['provenanceStatus'], $snapshot['assetContextId']);
        $episode->update(['rule_snapshot' => $snapshot]);
        $this->actingAs($this->member($this->allowed()))->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/alerts/'.$episode->id.'/acknowledge')->assertNotFound();
    }

    public function test_admin_location_edits_revoke_sessions_and_canonical_noops_preserve_them(): void
    {
        $user = $this->member();
        $this->actingAs($this->admin)->patchJson('/api/v1/users/'.$user->uuid, $this->userInput($user, ['location_access' => $this->allowed()]))->assertOk()->assertJsonPath('data.locationAccess.0.name', $this->zoneA->name);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->patchJson('/api/v1/users/'.$user->uuid, $this->userInput($user, ['location_access' => [...$this->allowed(), ...$this->allowed()]]))->assertOk();
        $this->assertSame(1, $user->fresh()->session_version);
        $this->patchJson('/api/v1/users/'.$user->uuid, $this->userInput($user))->assertOk();
        $this->assertSame($this->allowed(), $user->fresh()->location_access);
        $this->patchJson('/api/v1/users/'.$user->uuid, $this->userInput($user, ['location_access' => null]))->assertOk();
        $this->assertSame(2, $user->fresh()->session_version);
    }

    public function test_invalid_locations_are_rejected_and_members_cannot_change_their_own_scope(): void
    {
        $user = $this->member($this->allowed());
        $this->actingAs($this->admin)->patchJson('/api/v1/users/'.$user->uuid, $this->userInput($user, ['location_access' => [['type' => 'zone', 'id' => (string) Str::uuid()]]]))->assertUnprocessable();
        $this->actingAs($user)->patchJson('/api/v1/users/'.$user->uuid, $this->userInput($user, ['location_access' => null]))->assertForbidden();
    }

    public function test_http_credentials_stop_working_if_the_device_moves_out_of_the_issuers_scope(): void
    {
        $sensor = $this->sensor($this->asset($this->location()));
        $issuer = $this->member($this->allowed(), ['integrations.manage']);
        [$source] = app(HttpSourceAdministration::class)->apply($issuer, ['sensor_id' => $sensor->id, 'expires_days' => 90]);
        $this->assertTrue($source->credentialCurrent());
        app(AssetAdministration::class)->save($this->admin, ['name' => $sensor->device->name, 'locations' => [['type' => 'site', 'id' => $this->siteB->id]]], $sensor->device);
        $this->assertFalse($source->fresh()->credentialCurrent());
        $this->actingAs($this->admin)->getJson('/api/v1/integrations/sources')->assertOk()->assertJsonPath('data.0.credentialCurrent', false);
    }

    public function test_notification_outbox_and_delivery_recheck_recipient_location_access(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'sender@example.test', 'mail.mailers.smtp.host' => 'fictional-mail']);
        app(NotificationAdministration::class)->saveSettings($this->admin, [...app(NotificationConfiguration::class)->settings(), 'email_enabled' => true]);
        $inside = $this->member($this->allowed());
        $outside = $this->member([['type' => 'site', 'id' => $this->siteB->id]]);
        foreach ([$inside, $outside] as $user) {
            app(NotificationAdministration::class)->savePreferences($user, [...app(NotificationAdministration::class)->preferences($user), 'email_enabled' => true]);
        }
        $sensor = $this->sensor($this->asset($this->location()));
        $rule = AlertRule::create(['sensor_id' => $sensor->id, 'name' => 'Scoped Notification Rule', 'kind' => 'missing', 'severity' => 'warning', 'delay_seconds' => 120, 'activated_at' => now()->subHour()]);
        app(AlertEvaluator::class)->evaluate($rule);
        $delivery = NotificationDelivery::sole();
        $this->assertSame($inside->id, $delivery->user_id);
        $inside->location_access = [];
        $inside->save();
        $this->assertSame('skipped', app(NotificationDispatcher::class)->dispatch($delivery->id));
        $this->assertSame('cancelled', $delivery->fresh()->status);
        Mail::assertNothingSent();
        $this->actingAs($inside)->getJson('/api/v1/notifications/deliveries')->assertOk()->assertJsonCount(0, 'data');
    }
}
