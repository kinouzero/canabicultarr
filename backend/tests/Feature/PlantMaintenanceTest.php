<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\LifecycleSetting;
use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\DocumentLibrary;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlantMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private Plant $plant;

    protected function setUp(): void
    {
        parent::setUp();
        // Custom-stage capability remains configurable independently of the default cycle.
        $settings = LifecycleSetting::findOrFail(1);
        $stages = $settings->stages;
        $stages[1]['maintenance'] = true;
        $settings->update(['stages' => $stages]);
        Cache::flush();
        Http::preventStrayRequests();
        Storage::fake('documents');
        $this->travelTo(now()->startOfSecond());
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->site = Site::create(['name' => 'Fictional Maintenance Site']);
        $strain = Strain::create(['name' => 'Fictional Strain']);
        $this->plant = app(PlantTracking::class)->create($this->admin, ['name' => 'Fictional Plant', 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $this->site->id], 'request_id' => (string) Str::uuid()]);
    }

    private function input(array $changes = []): array
    {
        return [...['version' => $this->plant->fresh()->version, 'request_id' => (string) Str::uuid(), 'type' => 'topping'], ...$changes];
    }

    private function url(string $suffix = 'maintenance'): string
    {
        return '/api/v1/plants/'.$this->plant->id.'/'.$suffix;
    }

    private function document(): Document
    {
        return app(DocumentLibrary::class)->upload($this->admin, ['title' => 'Fictional Note', 'subject' => ['type' => 'site', 'id' => $this->site->id]], UploadedFile::fake()->createWithContent('note.txt', 'Fictional maintenance observation.'));
    }

    public function test_maintenance_requires_current_administrator_and_valid_explicit_type(): void
    {
        $this->postJson($this->url(), $this->input())->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($this->url(), $this->input())->assertForbidden();
        $restricted = User::factory()->create(['location_access' => []]);
        $this->actingAs($restricted)->postJson($this->url(), $this->input())->assertNotFound();
        $this->actingAs($this->admin);
        foreach ([['type' => str_repeat('x', 81)], ['type' => '   '], ['type' => ['pruning']], ['type' => null], ['version' => 0], ['request_id' => 'bad'], ['occurred_at' => now()->addMinute()->toISOString()], ['occurred_at' => now()->subDay()->toISOString()], ['occurred_at' => '2026-01-01 10:00'], ['comment' => str_repeat('x', 2001)], ['document_ids' => [(string) Str::uuid(), (string) Str::uuid()]], ['document_ids' => array_fill(0, 11, (string) Str::uuid())]] as $invalid) {
            $this->postJson($this->url(), $this->input($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('plant_events', 1);
    }

    public function test_custom_types_are_normalized_reusable_and_idempotent(): void
    {
        $this->actingAs($this->admin);
        $input = $this->input(['type' => '  Leaf   inspection  ']);
        $this->postJson($this->url(), $input)->assertOk();
        $this->postJson($this->url(), [...$input, 'type' => 'Leaf inspection'])->assertOk();
        $this->getJson($this->url('events'))->assertOk()->assertJsonPath('data.0.maintenance.type', 'Leaf inspection');
        $this->getJson('/api/v1/plants/maintenance-types')->assertOk()->assertExactJson(['data' => ['Leaf inspection']]);
        $this->postJson($this->url(), $this->input(['type' => 'Leaf inspection']))->assertOk();
        $this->postJson($this->url(), $this->input(['type' => 'leaf inspection']))->assertOk();
        $this->postJson($this->url(), $this->input(['type' => 'pruning']))->assertOk();
        $this->getJson('/api/v1/plants/maintenance-types')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('plant_events', 5);
    }

    public function test_type_catalog_requires_an_administrator(): void
    {
        foreach (['maintenance-types', 'plants/maintenance-types'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertUnauthorized();
            $this->actingAs(User::factory()->create())->getJson('/api/v1/'.$path)->assertForbidden();
            Auth::forgetGuards();
        }
        $this->actingAs(User::factory()->create())->postJson('/api/v1/maintenance-types', ['name' => 'Inspection'])->assertForbidden();
    }

    public function test_catalog_cleanup_preserves_history_and_retries_do_not_restore_deleted_choices(): void
    {
        $this->actingAs($this->admin);
        $input = $this->input(['type' => 'Leaf inspection']);
        $this->postJson($this->url(), $input)->assertOk();
        $record = $this->getJson('/api/v1/maintenance-types')->assertOk()->json('data.0');
        $path = '/api/v1/maintenance-types/'.$record['id'];
        $this->patchJson($path, ['name' => '  Weekly   inspection  ', 'version' => 1])->assertOk()->assertJsonPath('data.name', 'Weekly inspection')->assertJsonPath('data.version', 2);
        $this->patchJson($path, ['name' => 'Stale edit', 'version' => 1])->assertConflict();
        $this->deleteJson($path, ['version' => 1])->assertConflict();
        $this->getJson($this->url('events'))->assertOk()->assertJsonPath('data.0.maintenance.type', 'Leaf inspection');
        $this->getJson('/api/v1/plants/maintenance-types')->assertExactJson(['data' => ['Weekly inspection']]);
        $this->deleteJson($path, ['version' => 2])->assertNoContent();
        $this->postJson($this->url(), $input)->assertOk();
        $this->getJson('/api/v1/plants/maintenance-types')->assertExactJson(['data' => []]);
        $this->getJson($this->url('events'))->assertJsonPath('data.0.maintenance.type', 'Leaf inspection');
        $this->assertDatabaseCount('plant_events', 2);
        $created = $this->postJson('/api/v1/maintenance-types', ['name' => 'Weekly inspection'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/maintenance-types', ['name' => 'weekly inspection'])->assertUnprocessable();
        $this->postJson('/api/v1/maintenance-types', ['name' => 'topping'])->assertUnprocessable();
        $this->patchJson('/api/v1/maintenance-types/'.$created, ['name' => '', 'version' => 1])->assertUnprocessable();
    }

    public function test_catalog_migration_preserves_custom_historical_labels(): void
    {
        $this->actingAs($this->admin)->postJson($this->url(), $this->input(['type' => 'Historical inspection']))->assertOk();
        $migration = require database_path('migrations/2026_11_09_233000_create_plant_maintenance_types.php');
        $migration->down();
        $migration->up();
        $this->getJson('/api/v1/plants/maintenance-types')->assertExactJson(['data' => ['Historical inspection']]);
        $this->assertDatabaseCount('plant_events', 2);
    }

    public function test_repeated_maintenance_keeps_lifecycle_and_watering_unchanged(): void
    {
        $before = $this->plant->only(['phase', 'phase_started_at', 'phase_due_at', 'duration_snapshot']);
        $this->actingAs($this->admin);
        foreach (['topping', 'pruning', 'other', 'topping'] as $type) {
            $this->postJson($this->url(), $this->input(['type' => $type, 'comment' => 'Fictional observation']))->assertOk()->assertJsonPath('data.watering.status', 'unavailable');
        }
        $this->assertEquals($before, $this->plant->fresh()->only(array_keys($before)));
        $this->assertSame(5, $this->plant->fresh()->version);
        $this->getJson($this->url('events'))->assertOk()->assertJsonPath('data.0.maintenance.type', 'topping')->assertJsonPath('data.0.comment', 'Fictional observation')->assertJsonPath('data.0.fromPhase', 'sowing')->assertJsonPath('data.0.toPhase', 'sowing');
    }

    public function test_maintenance_replay_and_payload_actor_version_conflicts(): void
    {
        $this->actingAs($this->admin);
        $data = $this->input();
        $this->postJson($this->url(), $data)->assertOk();
        $this->postJson($this->url(), $data)->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson($this->url(), [...$data, 'type' => 'pruning'])->assertConflict();
        $this->postJson($this->url(), [...$data, 'request_id' => (string) Str::uuid()])->assertConflict();
        $other = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($other)->postJson($this->url(), $data)->assertConflict();
        $this->actingAs($this->admin)->postJson($this->url('waterings'), [...$data, 'with_products' => false])->assertConflict();
        $this->assertDatabaseCount('plant_events', 2);
    }

    public function test_fresh_session_and_role_are_checked_inside_maintenance_transaction(): void
    {
        $stale = $this->admin->fresh();
        $this->admin->forceFill(['session_version' => 1])->save();
        try {
            app(PlantTracking::class)->maintain($stale, $this->plant, $this->input());
            $this->fail('Expected stale session rejection');
        } catch (AuthenticationException) {
        }
        $current = $this->admin->fresh();
        $this->admin->forceFill(['permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id]])->save();
        try {
            app(PlantTracking::class)->maintain($current, $this->plant, $this->input());
            $this->fail('Expected demoted actor rejection');
        } catch (AuthorizationException) {
        }
        $this->assertDatabaseCount('plant_events', 1);
    }

    public function test_multiple_document_links_and_canonical_retry_do_not_duplicate_files_or_events(): void
    {
        $first = $this->document();
        $second = $this->document();
        $this->actingAs($this->admin);
        $data = $this->input(['document_ids' => [$first->id, $second->id]]);
        $this->postJson($this->url(), $data)->assertOk();
        $this->postJson($this->url(), [...$data, 'document_ids' => [$second->id, $first->id]])->assertOk();
        $this->postJson($this->url(), [...$data, 'document_ids' => [$first->id]])->assertConflict();
        $this->getJson($this->url('events'))->assertOk()->assertJsonCount(2, 'data.0.attachments')->assertJsonMissingPath('data.0.attachments.0.path')->assertJsonMissingPath('data.0.attachments.0.sha256');
        $this->assertDatabaseCount('documents', 2);
        $this->assertDatabaseCount('plant_event_documents', 2);
        $this->assertDatabaseCount('plant_events', 2);
    }

    public function test_archived_missing_or_duplicate_documents_roll_back_without_advancing_plant(): void
    {
        $document = $this->document();
        $document->update(['is_archived' => true]);
        $this->actingAs($this->admin);
        foreach ([[$document->id], [(string) Str::uuid()], [$document->id, $document->id]] as $ids) {
            $this->postJson($this->url(), $this->input(['document_ids' => $ids]))->assertUnprocessable();
        }
        $this->assertSame(1, $this->plant->fresh()->version);
        $this->assertDatabaseCount('plant_event_documents', 0);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_lifecycle_and_watering_support_the_same_private_attachments(): void
    {
        $document = $this->document();
        $this->actingAs($this->admin);
        $this->postJson($this->url('actions'), $this->input(['action' => 'plant', 'document_ids' => [$document->id]]))->assertOk()->assertJsonPath('data.phase', 'germination');
        $this->postJson($this->url('waterings'), $this->input(['with_products' => false, 'document_ids' => [$document->id]]))->assertOk();
        $this->getJson($this->url('events'))->assertOk()->assertJsonCount(1, 'data.0.attachments')->assertJsonCount(1, 'data.1.attachments');
        $this->assertDatabaseCount('plant_event_documents', 2);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_document_scope_is_checked_independently_from_visible_event_and_plant(): void
    {
        $document = $this->document();
        $this->actingAs($this->admin)->postJson($this->url(), $this->input(['document_ids' => [$document->id]]))->assertOk();
        $other = Site::create(['name' => 'Other Fictional Site']);
        $document->forceFill(['site_id' => $other->id, 'subject_snapshot' => ['type' => 'site', 'id' => $other->id, 'location' => ['site' => ['id' => $other->id]]]])->save();
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($reader)->getJson($this->url('events'))->assertOk()->assertJsonCount(0, 'data.0.attachments')->assertJsonPath('data.0.maintenance.type', 'topping');
        $this->getJson('/api/v1/documents/'.$document->id.'/download')->assertNotFound();
        $this->actingAs($this->admin)->getJson($this->url('events'))->assertOk()->assertJsonCount(1, 'data.0.attachments');
    }

    public function test_captured_history_location_keeps_attachments_private_after_plant_moves(): void
    {
        $document = $this->document();
        $this->actingAs($this->admin)->postJson($this->url(), $this->input(['document_ids' => [$document->id]]))->assertOk();
        $other = Site::create(['name' => 'Other Fictional Site']);
        $this->patchJson('/api/v1/plants/'.$this->plant->id, ['version' => 2, 'location' => ['type' => 'site', 'id' => $other->id]])->assertOk();
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $other->id]]]);
        $this->actingAs($reader)->getJson($this->url('events'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_maintenance_is_unavailable_after_archive_but_existing_entries_are_readable(): void
    {
        $this->actingAs($this->admin)->postJson($this->url(), $this->input())->assertOk();
        $this->plant->refresh()->forceFill(['phase' => 'archived'])->save();
        $this->postJson($this->url(), $this->input())->assertConflict()->assertJsonPath('error.code', 'plants.errors.maintenanceUnavailable');
        $this->getJson($this->url('events'))->assertOk()->assertJsonPath('data.0.maintenance.type', 'topping');
    }

    public function test_empty_document_input_uses_the_canonical_watering_request_hash(): void
    {
        $key = (string) Str::uuid();
        $hash = hash('sha256', json_encode(['water_liters' => null, 'fertilizers' => [], 'action' => 'watered', 'with_products' => false, 'comment' => null, 'occurred_at' => null], JSON_THROW_ON_ERROR));
        PlantEvent::create(['plant_id' => $this->plant->id, 'plant_version' => 2, 'kind' => 'watered', 'from_phase' => 'sowing', 'to_phase' => 'sowing', 'actor_uuid' => $this->admin->uuid, 'request_id' => $key, 'request_hash' => $hash, 'occurred_at' => now(), 'recorded_at' => now(), 'fields' => [], 'snapshot' => ['location' => $this->plant->locationDetails(), 'watering' => ['with_products' => false, 'during_flush' => false]]]);
        $this->plant->forceFill(['version' => 2])->save();
        $this->actingAs($this->admin)->postJson($this->url('waterings'), ['version' => 1, 'request_id' => $key, 'with_products' => false, 'document_ids' => []])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('plant_events', 2);
    }
}
