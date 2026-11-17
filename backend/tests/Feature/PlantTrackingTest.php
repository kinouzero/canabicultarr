<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\LifecycleSetting;
use App\Models\Plant;
use App\Models\Site;
use App\Models\StorageLocation;
use App\Models\Strain;
use App\Models\User;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlantTrackingTest extends TestCase
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
        $this->travelTo(now()->startOfSecond());
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->site = Site::create(['name' => 'Fictional Plant Site']);
        $this->strain = Strain::create(['name' => 'Fictional Plant Strain']);
    }

    private function input(array $extra = []): array
    {
        return ['name' => 'Fictional Plant', 'request_id' => (string) Str::uuid(), 'strain_id' => $this->strain->id, 'location' => ['type' => 'site', 'id' => $this->site->id], ...$extra];
    }

    private function create(array $extra = []): Plant
    {
        return app(PlantTracking::class)->create($this->admin, $this->input($extra));
    }

    private function action(Plant $plant, string $action, array $extra = []): Plant
    {
        return app(PlantTracking::class)->action($this->admin, $plant, ['action' => $action, 'version' => $plant->version, 'request_id' => (string) Str::uuid(), ...$extra]);
    }

    public function test_planned_plant_has_no_fabricated_dates_and_starts_only_on_sow_action(): void
    {
        $input = $this->input(['planned' => true]);
        $response = $this->actingAs($this->admin)->postJson('/api/v1/plants', $input)->assertCreated()
            ->assertJsonPath('data.phase', 'planned')->assertJsonPath('data.startedAt', null)
            ->assertJsonPath('data.phaseStartedAt', null)->assertJsonPath('data.phaseDueAt', null)
            ->assertJsonPath('data.day', null)->assertJsonPath('data.week', null)
            ->assertJsonPath('data.nextAction', 'sow')->assertJsonPath('data.watering.status', 'unavailable');
        $id = $response->json('data.id');
        $this->postJson('/api/v1/plants', $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/plants', [...$input, 'planned' => false])->assertConflict();
        $this->postJson('/api/v1/plants', $this->input(['planned' => true, 'started_at' => now()->toISOString()]))->assertUnprocessable();
        $this->postJson('/api/v1/plants/'.$id.'/waterings', ['version' => 1, 'request_id' => (string) Str::uuid(), 'with_products' => false])->assertConflict();
        $this->postJson('/api/v1/plants/'.$id.'/maintenance', ['version' => 1, 'request_id' => (string) Str::uuid(), 'type' => 'pruning'])->assertConflict();
        $this->postJson('/api/v1/plants/'.$id.'/actions', ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'plant'])->assertConflict();
        $this->travel(2)->days();
        $action = ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'sow'];
        $this->postJson('/api/v1/plants/'.$id.'/actions', $action)->assertOk()
            ->assertJsonPath('data.phase', 'sowing')->assertJsonPath('data.startedAt', now()->toISOString())
            ->assertJsonPath('data.phaseStartedAt', now()->toISOString())->assertJsonPath('data.phaseDueAt', now()->addDays(3)->toISOString())
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.day', 1);
        $this->postJson('/api/v1/plants/'.$id.'/actions', $action)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('plant_events', 2);
    }

    public function test_imported_unknown_phase_start_stays_unknown_until_an_actual_transition(): void
    {
        $plant = $this->create();
        $plant->forceFill(['phase' => 'flowering', 'phase_started_at' => null, 'phase_due_at' => null])->save();
        $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$plant->id)->assertOk()
            ->assertJsonPath('data.phaseStartedAt', null)->assertJsonPath('data.day', null)
            ->assertJsonPath('data.watering.flushStartsAt', null);
        $plant = $this->action($plant, 'harvest');
        $this->assertSame(now()->toISOString(), $plant->phase_started_at->toISOString());
        $this->assertSame('hanging_drying', $plant->phase);
    }

    public function test_planned_plants_have_a_separate_status_and_are_excluded_from_active_lists(): void
    {
        $planned = $this->create(['planned' => true]);
        $active = $this->create();
        $archived = $this->create();
        $archived->forceFill(['phase' => 'archived'])->save();
        $this->actingAs($this->admin)->getJson('/api/v1/plants')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/v1/plants?status=planned')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $planned->id);
        $this->getJson('/api/v1/plants?status=archived')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $archived->id);
        $this->getJson('/api/v1/plants?status=all')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_guests_cannot_read_or_write_and_members_cannot_write(): void
    {
        $plant = $this->create();
        $this->getJson('/api/v1/plants')->assertUnauthorized();
        $this->getJson('/api/v1/plants/'.$plant->id.'/events')->assertUnauthorized();
        $this->postJson('/api/v1/plants', $this->input())->assertUnauthorized();
        $member = User::factory()->create();
        $this->actingAs($member)->getJson('/api/v1/plants')->assertOk()->assertJsonPath('data.0.canManage', false);
        $this->postJson('/api/v1/plants', $this->input())->assertForbidden();
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'name' => 'Denied'])->assertForbidden();
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', [])->assertForbidden();
    }

    public function test_create_captures_strain_defaults_and_allows_distinct_same_strain_plants(): void
    {
        $this->strain->duration_overrides = ['growth' => 28, 'flush' => 7];
        $this->strain->save();
        $response = $this->actingAs($this->admin)->postJson('/api/v1/plants', $this->input())->assertCreated()->assertJsonPath('data.phase', 'sowing')->assertJsonPath('data.version', 1)->assertJsonPath('data.durationSnapshot.durations.growth', 28)->assertJsonPath('data.durationSnapshot.durations.flush', 7);
        $id = $response->json('data.id');
        $this->postJson('/api/v1/plants', $this->input())->assertCreated();
        $this->assertDatabaseCount('plants', 2);
        $this->assertDatabaseCount('plant_events', 2);
        $this->getJson('/api/v1/plants/'.$id)->assertOk()->assertJsonPath('data.strain.id', $this->strain->id)->assertJsonPath('data.location.site.id', $this->site->id);
        $this->assertArrayNotHasKey('creation_hash', $response->json('data'));
    }

    public function test_creation_replay_is_idempotent_and_changed_payload_is_rejected(): void
    {
        $input = $this->input();
        $first = $this->actingAs($this->admin)->postJson('/api/v1/plants', $input)->assertCreated();
        $this->postJson('/api/v1/plants', $input)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/v1/plants', [...$input, 'name' => 'Changed Replay'])->assertConflict();
        $this->assertDatabaseCount('plants', 1);
        $this->assertDatabaseCount('plant_events', 1);
    }

    public function test_global_and_strain_edits_never_change_existing_duration_snapshot(): void
    {
        $plant = $this->create();
        $snapshot = $plant->duration_snapshot;
        $settings = LifecycleSetting::findOrFail(1);
        $settings->durations = [...$settings->durations, 'growth' => 35];
        $settings->save();
        $this->strain->duration_overrides = ['curing' => 21];
        $this->strain->save();
        $this->assertSame($snapshot, $plant->fresh()->duration_snapshot);
        $next = $this->create();
        $this->assertSame(35, $next->duration_snapshot['durations']['growth']);
        $this->assertSame(21, $next->duration_snapshot['durations']['curing']);
    }

    public function test_week_progress_is_automatic_but_overdue_actions_do_not_advance_phases(): void
    {
        $plant = $this->create(['started_at' => now()->subDays(9)->toISOString()]);
        $response = $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.week', 2)->assertJsonPath('data.day', 10)->assertJsonPath('data.isOverdue', true)->assertJsonPath('data.phase', 'sowing')->assertJsonPath('data.nextAction', 'plant');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('sowing', $plant->fresh()->phase);
    }

    public function test_complete_lifecycle_requires_explicit_maturation_with_harvest_weight(): void
    {
        $plant = $this->create();
        $id = $plant->id;
        foreach (['plant', 'repot_growth', 'repot_flowering', 'harvest', 'transfer_to_rack', 'transfer_to_containers'] as $action) {
            $plant = $this->action($plant, $action, ['comment' => 'Fictional action comment']);
            $this->assertSame($id, $plant->id);
        }
        $this->assertSame('curing', $plant->phase);
        $due = $plant->phase_due_at;
        $this->travelTo($due->addDays(2));
        $this->assertStringNotContainsString('plants:mature', implode(' ', array_map(fn ($event) => $event->command ?? '', app(Schedule::class)->events())));
        $this->assertSame('curing', $plant->fresh()->phase);
        $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$id)->assertOk()
            ->assertJsonPath('data.nextAction', 'start_maturation')->assertJsonPath('data.isOverdue', true);
        $plant = $this->action($plant, 'start_maturation', ['harvest_weight_grams' => '123.45']);
        $this->assertSame('maturation', $plant->phase);
        $this->assertSame('123.45', $plant->harvest_weight_grams);
        $this->assertSame(now()->toISOString(), $plant->phase_started_at->toISOString());
        $this->assertNull($plant->phase_due_at);
        $plant = $this->action($plant, 'archive');
        $this->assertSame('archived', $plant->phase);
        $this->assertSame($id, $plant->id);
        $this->assertSame(9, $plant->events()->count());
        $this->actingAs($this->admin)->getJson('/api/v1/plants')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/plants?status=archived')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/plants/'.$id.'/events')->assertOk()->assertJsonCount(9, 'data');
        $this->patchJson('/api/v1/plants/'.$id, ['version' => $plant->version, 'notes' => 'Denied'])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_maturation_validates_weight_replay_phase_permissions_and_history(): void
    {
        $plant = $this->create();
        $plant->forceFill(['phase' => 'curing', 'phase_due_at' => now()->addDays(14)])->save();
        $url = '/api/v1/plants/'.$plant->id.'/actions';
        $input = ['action' => 'start_maturation', 'version' => $plant->version, 'request_id' => (string) Str::uuid()];
        $this->actingAs($this->admin);
        foreach ([null, '', 0, -1, 'abc', '1.234', '10000000000', [], true] as $value) {
            $this->postJson($url, [...$input, 'harvest_weight_grams' => $value])->assertUnprocessable();
        }
        $this->postJson($url, $input)->assertUnprocessable();
        $this->assertSame('curing', $plant->fresh()->phase);
        $this->assertNull($plant->fresh()->harvest_weight_grams);
        $input['harvest_weight_grams'] = '12.50';
        $input['container'] = null;
        $this->postJson($url, [...$input, 'version' => 99])->assertConflict();
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.phase', 'maturation')
            ->assertJsonPath('data.harvestWeightGrams', '12.50')->assertJsonPath('data.version', 2);
        $this->postJson($url, [...$input, 'harvest_weight_grams' => 12.5])->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson($url, [...$input, 'harvest_weight_grams' => 13])->assertConflict();
        $this->postJson($url, [...$input, 'version' => 2, 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson($url, ['action' => 'archive', 'version' => 2, 'request_id' => (string) Str::uuid(), 'harvest_weight_grams' => 1])->assertUnprocessable();
        $this->getJson('/api/v1/plants/'.$plant->id.'/events')->assertOk()
            ->assertJsonPath('data.0.kind', 'start_maturation')->assertJsonPath('data.0.harvestWeightGrams', '12.50')
            ->assertJsonPath('data.0.actorId', $this->admin->uuid);
        $this->assertSame(2, $plant->events()->count());
        $archived = $this->action($plant->fresh(), 'archive');
        $this->assertSame('12.50', $archived->harvest_weight_grams);
        $reader = User::factory()->create();
        $this->actingAs($reader)->postJson($url, $input)->assertForbidden();
    }

    public function test_lifecycle_api_accepts_no_container_without_requiring_nested_fields(): void
    {
        $plant = $this->create();
        $url = '/api/v1/plants/'.$plant->id.'/actions';
        $this->actingAs($this->admin)->postJson($url, ['action' => 'plant', 'version' => 1, 'request_id' => (string) Str::uuid(), 'container' => null, 'substrates' => []])
            ->assertOk()->assertJsonPath('data.phase', 'germination');
        $this->postJson($url, ['action' => 'repot_growth', 'version' => 2, 'request_id' => (string) Str::uuid(), 'container' => ['inventory_item_id' => (string) Str::uuid()]])
            ->assertUnprocessable()->assertJsonValidationErrors(['container.balance_id', 'container.previous']);
        $this->assertSame('germination', $plant->fresh()->phase);
        $this->assertSame(2, $plant->events()->count());
    }

    public function test_action_replay_does_not_duplicate_history_and_cannot_change_comment(): void
    {
        $plant = $this->create();
        $input = ['action' => 'plant', 'request_id' => (string) Str::uuid(), 'version' => 1, 'comment' => 'Original'];
        $this->actingAs($this->admin)->postJson('/api/v1/plants/'.$plant->id.'/actions', $input)->assertOk()->assertJsonPath('data.phase', 'germination');
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', $input)->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', [...$input, 'comment' => 'Changed'])->assertConflict();
        $this->assertSame(2, $plant->events()->count());
    }

    public function test_invalid_or_out_of_order_actions_and_stale_versions_are_rejected(): void
    {
        $plant = $this->create();
        $this->actingAs($this->admin)->postJson('/api/v1/plants/'.$plant->id.'/actions', ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'harvest'])->assertConflict();
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'name' => 'Renamed'])->assertOk();
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'name' => 'Stale'])->assertConflict();
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'plant'])->assertConflict();
        $this->assertSame('sowing', $plant->fresh()->phase);
    }

    public function test_invalid_times_unknown_devices_and_archived_strains_are_rejected_without_side_effects(): void
    {
        $this->actingAs($this->admin);
        foreach ([['started_at' => now()->addDay()->toISOString()], ['started_at' => '2026-11-01T00:00:00'], ['started_at' => '1999-01-01T00:00:00Z'], ['location' => null], ['device_ids' => [(string) Str::uuid()]], ['duration_snapshot' => ['growth' => 1]], ['phase' => 'maturation']] as $extra) {
            $this->postJson('/api/v1/plants', $this->input($extra))->assertUnprocessable();
        }
        $this->strain->is_archived = true;
        $this->strain->save();
        $this->postJson('/api/v1/plants', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('plants', 0);
        $this->assertDatabaseCount('plant_events', 0);
    }

    public function test_manual_hardware_links_without_placeholders_and_retired_hardware_is_rejected(): void
    {
        $device = Asset::create(['name' => 'Fictional Device']);
        $equipment = Asset::create(['name' => 'Fictional Equipment']);
        $retired = Asset::create(['name' => 'Retired Device', 'status' => 'retired']);
        $this->actingAs($this->admin);
        foreach ([$retired->id] as $id) {
            $this->postJson('/api/v1/plants', $this->input(['device_ids' => [$id]]))->assertUnprocessable();
        }
        $manual = $this->create(['device_ids' => [$equipment->id]]);
        $this->getJson('/api/v1/plants/'.$manual->id)->assertOk()->assertJsonPath('data.devices.0.id', $equipment->id);
        $plant = $this->create(['device_ids' => [$device->id]]);
        $this->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.devices.0.id', $device->id);
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'notes' => 'Keep link'])->assertOk()->assertJsonPath('data.devices.0.id', $device->id);
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 2, 'device_ids' => []])->assertOk()->assertJsonCount(0, 'data.devices');
        $this->assertDatabaseCount('assets', 3);
    }

    public function test_geographic_scope_filters_list_detail_events_and_device_names(): void
    {
        $room = $this->site->rooms()->create(['name' => 'Allowed Room']);
        $zone = $room->zones()->create(['name' => 'Allowed Zone']);
        $other = Site::create(['name' => 'Private Other Site']);
        $device = $this->placeAsset(new Asset(['name' => 'Hidden Device']), ['site_id' => $other->id]);
        $allowed = $this->create(['name' => 'Allowed Plant', 'location' => ['type' => 'zone', 'id' => $zone->id], 'device_ids' => [$device->id]]);
        $hidden = $this->create(['name' => 'Private Plant', 'location' => ['type' => 'site', 'id' => $other->id]]);
        $member = User::factory()->create(['location_access' => [['type' => 'zone', 'id' => $zone->id]]]);
        $this->actingAs($member)->getJson('/api/v1/plants')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed->id);
        $this->getJson('/api/v1/plants/'.$allowed->id)->assertOk()->assertJsonCount(0, 'data.devices');
        $this->getJson('/api/v1/plants/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/v1/plants/'.$hidden->id.'/events')->assertNotFound();
        $this->getJson('/api/v1/plants/'.$allowed->id.'/events')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/plants?site_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
        $member->location_access = [];
        $member->save();
        $this->actingAs($member->fresh())->getJson('/api/v1/plants')->assertJsonCount(0, 'data');
    }

    public function test_moved_in_plant_does_not_expose_old_outside_history_or_move_origin(): void
    {
        $other = Site::create(['name' => 'Hidden Origin']);
        $plant = $this->create(['location' => ['type' => 'site', 'id' => $other->id]]);
        $plant = app(PlantTracking::class)->update($this->admin, $plant, ['version' => 1, 'location' => ['type' => 'site', 'id' => $this->site->id]]);
        $plant = $this->action($plant, 'plant');
        $member = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $result = $this->actingAs($member)->getJson('/api/v1/plants/'.$plant->id.'/events')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'plant');
        $this->assertStringNotContainsString('Hidden Origin', $result->getContent());
    }

    public function test_update_noop_preserves_version_including_an_unchanged_strain(): void
    {
        $plant = $this->create();
        $this->actingAs($this->admin)->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'name' => $plant->name])->assertOk()->assertJsonPath('data.version', 1);
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'strain_id' => $this->strain->id])->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(1, $plant->events()->count());
    }

    public function test_strain_correction_keeps_schedule_stock_and_past_events_and_snapshots_the_reason(): void
    {
        $oldSeeds = InventoryItem::create(['name' => 'Original seeds', 'type' => 'seeds', 'unit' => 'piece']);
        $newSeeds = InventoryItem::create(['name' => 'Corrected seeds', 'type' => 'seeds', 'unit' => 'piece']);
        $oldSeeds->forceFill(['total_units' => 5000])->save();
        $newSeeds->forceFill(['total_units' => 7000])->save();
        $oldSeeds->total_units = 1000;
        $oldSeeds->save();
        $this->strain->update(['seed_inventory_item_id' => $oldSeeds->id]);
        $replacement = Strain::create(['name' => 'Corrected strain', 'duration_overrides' => ['sowing' => 6, 'growth' => 42], 'seed_inventory_item_id' => $newSeeds->id]);
        $plant = $this->create();
        $previousEvents = $plant->events()->orderBy('id')->get()->toJson();
        $snapshot = $plant->duration_snapshot;
        $schedule = [$plant->started_at, $plant->phase_started_at, $plant->phase_due_at];
        $movements = DB::table('inventory_movements')->orderBy('id')->get()->toJson();
        $stocks = [$oldSeeds->fresh()->total_units, $newSeeds->fresh()->total_units];
        $this->actingAs($this->admin)->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'strain_id' => $replacement->id, 'strain_change_reason' => '  Corrected the seed label  '])->assertOk()
            ->assertJsonPath('data.strain.id', $replacement->id)->assertJsonPath('data.version', 2)->assertJsonPath('data.durationSnapshot', $snapshot);
        $updated = $plant->fresh();
        $this->assertEquals($schedule, [$updated->started_at, $updated->phase_started_at, $updated->phase_due_at]);
        $this->assertSame($stocks, [$oldSeeds->fresh()->total_units, $newSeeds->fresh()->total_units]);
        $this->assertSame($movements, DB::table('inventory_movements')->orderBy('id')->get()->toJson());
        $this->assertSame($previousEvents, $plant->events()->where('plant_version', 1)->orderBy('id')->get()->toJson());
        $replacement->update(['name' => 'Later renamed strain']);
        $this->getJson('/api/v1/plants/'.$plant->id.'/events')->assertOk()->assertJsonPath('data.0.kind', 'updated')
            ->assertJsonPath('data.0.actorId', $this->admin->uuid)->assertJsonPath('data.0.occurredAt', now()->toISOString())
            ->assertJsonPath('data.0.snapshot.strain_change', ['previous' => ['id' => $this->strain->id, 'name' => $this->strain->name], 'current' => ['id' => $replacement->id, 'name' => 'Corrected strain'], 'reason' => 'Corrected the seed label']);
        $this->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'strain_id' => $this->strain->id, 'strain_change_reason' => 'Stale correction'])->assertConflict();
        $this->assertSame(2, $plant->events()->count());
        $this->assertSame($replacement->id, $plant->fresh()->strain_id);
    }

    public function test_strain_correction_requires_a_reason_and_an_available_strain_without_partial_writes(): void
    {
        $plant = $this->create();
        $replacement = Strain::create(['name' => 'Replacement strain']);
        $input = ['version' => 1, 'strain_id' => $replacement->id, 'name' => 'Must not change'];
        $this->actingAs($this->admin);
        foreach ([null, '', " \t\n\u{00A0}", str_repeat('x', 2001)] as $reason) {
            $this->patchJson('/api/v1/plants/'.$plant->id, [...$input, 'strain_change_reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('strain_change_reason');
        }
        $replacement->update(['is_archived' => true]);
        foreach ([$replacement->id, (string) Str::uuid()] as $id) {
            $this->patchJson('/api/v1/plants/'.$plant->id, [...$input, 'strain_id' => $id, 'strain_change_reason' => 'Correction'])->assertUnprocessable();
        }
        $this->assertSame($plant->name, $plant->fresh()->name);
        $this->assertSame($this->strain->id, $plant->fresh()->strain_id);
        $this->assertSame(1, $plant->events()->count());
    }

    public function test_strain_correction_obeys_permissions_location_scope_and_archive_state(): void
    {
        $plant = $this->create();
        $replacement = Strain::create(['name' => 'Replacement strain']);
        $input = ['version' => 1, 'strain_id' => $replacement->id, 'strain_change_reason' => 'Correction'];
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->patchJson('/api/v1/plants/'.$plant->id, $input)->assertForbidden();
        $grower = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('grower')->id], 'location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $hidden = Site::create(['name' => 'Hidden site']);
        $this->actingAs($grower)->patchJson('/api/v1/plants/'.$plant->id, [...$input, 'location' => ['type' => 'site', 'id' => $hidden->id]])->assertNotFound();
        $this->assertSame($this->strain->id, $plant->fresh()->strain_id);
        $this->patchJson('/api/v1/plants/'.$plant->id, $input)->assertOk()->assertJsonPath('data.strain.id', $replacement->id);
        $grower->forceFill(['location_access' => []])->save();
        $this->actingAs($grower->fresh())->patchJson('/api/v1/plants/'.$plant->id, [...$input, 'version' => 2])->assertNotFound();
        $plant->forceFill(['phase' => 'archived'])->save();
        $this->actingAs($this->admin)->patchJson('/api/v1/plants/'.$plant->id, [...$input, 'version' => 2])->assertConflict();
        $this->assertSame(2, $plant->events()->count());
    }

    public function test_stale_administrator_is_rejected_inside_service(): void
    {
        $plant = $this->create();
        $this->admin->fresh()->permissionRoles()->sync([AccessProfileSeeder::defaultProfile()->id]);
        $this->expectException(AuthorizationException::class);
        app(PlantTracking::class)->update($this->admin, $plant, ['version' => 1, 'notes' => 'Denied']);
    }

    public function test_empty_seed_stock_blocks_started_and_planned_creation_but_not_confirmed_replays(): void
    {
        $seeds = InventoryItem::create(['name' => 'Empty seeds', 'type' => 'seeds', 'unit' => 'piece']);
        $this->strain->update(['seed_inventory_item_id' => $seeds->id]);
        $this->actingAs($this->admin);
        foreach ([false, true] as $planned) {
            $this->postJson('/api/v1/plants', $this->input(['planned' => $planned]))->assertUnprocessable()->assertJsonPath('error.code', 'plants.errors.seedStockEmpty');
        }
        $this->assertSame(0, Plant::count());
        $seeds->total_units = 1000;
        $seeds->save();
        $input = $this->input(['planned' => true]);
        $created = $this->postJson('/api/v1/plants', $input)->assertCreated();
        $this->assertSame(1000, $seeds->fresh()->total_units);
        $seeds->total_units = 0;
        $seeds->save();
        $this->postJson('/api/v1/plants', $input)->assertCreated()->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, Plant::count());
    }

    public function test_creation_uses_the_same_accessible_seed_quantity_as_the_strain_record(): void
    {
        $seeds = InventoryItem::create(['name' => 'Scoped seeds', 'type' => 'seeds', 'unit' => 'piece']);
        $seeds->total_units = 5000;
        $seeds->save();
        $this->strain->update(['seed_inventory_item_id' => $seeds->id]);
        $storage = StorageLocation::create(['name' => 'Accessible seed storage']);
        $user = User::factory()->create(['permission_grants' => ['plants.manage'], 'inventory_location_access' => [$storage->id]]);
        $this->actingAs($user)->getJson('/api/v1/strains/'.$this->strain->id)->assertOk()->assertJsonPath('data.seedStock.quantity', '0.000');
        $this->postJson('/api/v1/plants', $this->input())->assertUnprocessable()->assertJsonPath('error.code', 'plants.errors.seedStockEmpty');
        InventoryBalance::create(['inventory_item_id' => $seeds->id, 'storage_location_id' => $storage->id, 'quantity_units' => 1000]);
        $this->postJson('/api/v1/plants', $this->input())->assertCreated();
        $this->assertSame(5000, $seeds->fresh()->total_units);
    }
}
