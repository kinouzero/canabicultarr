<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetContext;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Room;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Models\Zone;
use App\Services\AssetAdministration;
use App\Services\EventContext;
use App\Services\HttpMeasurementIngestion;
use App\Services\HttpSourceAdministration;
use App\Services\LocationAccess;
use App\Services\PlantTracking;
use App\Services\SourceDiagnostics;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetMultipleAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private Room $room;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->travelTo(now()->startOfSecond());
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->site = Site::create(['name' => 'First site']);
        $this->room = $this->site->rooms()->create(['name' => 'First room']);
        $this->zone = $this->room->zones()->create(['name' => 'First zone']);
    }

    private function placement(string $type = 'zone', ?string $id = null): array
    {
        return ['type' => $type, 'id' => $id ?? $this->zone->id];
    }

    private function hardware(array $locations): Asset
    {
        return app(AssetAdministration::class)->save($this->admin, ['name' => 'Shared hardware', 'locations' => $locations]);
    }

    private function sensor(Asset $asset): Sensor
    {
        $type = MeasurementType::create(['name' => 'Temperature', 'code' => 'multi-'.bin2hex(random_bytes(4)), 'unit' => '°C']);
        $sensor = new Sensor(['name' => 'Inherited channel', 'channel_key' => 'probe']);
        $sensor->device()->associate($asset);
        $sensor->measurementType()->associate($type);
        $sensor->save();

        return $sensor;
    }

    public function test_prepared_hardware_decisions_are_batched_and_invalidated_after_reassignment(): void
    {
        $assets = collect(range(1, 12))->map(fn () => $this->hardware([$this->placement()]));
        $reader = User::factory()->create(['location_access' => [$this->placement()]]);
        $access = app(LocationAccess::class);
        $access->clear();
        DB::enableQueryLog();
        $access->prepare($reader, $assets);
        $prepared = count(DB::getQueryLog());
        foreach ($assets as $asset) {
            $this->assertTrue($access->record($reader, $asset));
        }
        $this->assertSame($prepared, count(DB::getQueryLog()));
        $this->assertLessThanOrEqual(4, $prepared);
        DB::disableQueryLog();
        $other = Site::create(['name' => 'Outside site']);
        app(AssetAdministration::class)->save($this->admin, ['locations' => [$this->placement('site', $other->id)]], $assets->first());
        $this->assertFalse($access->record($reader, $assets->first()));
        $this->assertFalse($access->record($reader, $assets->first()->assignments()->latest('assigned_at')->latest('id')->firstOrFail()));
    }

    public function test_multiple_placements_are_explicit_in_hardware_channels_history_and_observation_context(): void
    {
        $second = Site::create(['name' => 'Second site']);
        $asset = $this->hardware([$this->placement(), $this->placement('site', $second->id)]);
        $sensor = $this->sensor($asset);
        $this->actingAs($this->admin)->getJson('/api/v1/assets')->assertOk()->assertJsonCount(2, 'data.0.locations')->assertJsonMissingPath('data.0.location');
        $this->getJson('/api/v1/sensors')->assertOk()->assertJsonCount(2, 'data.0.device.locations');
        $this->getJson('/api/v1/assets/'.$asset->id.'/assignments')->assertOk()->assertJsonCount(2, 'data.0.locations');
        $this->assertDatabaseCount('asset_locations', 2);
        $this->assertDatabaseCount('asset_contexts', 1);
        $this->assertNull($asset->locationDetails());
        [$source, $token] = app(HttpSourceAdministration::class)->apply($this->admin, ['sensor_id' => $sensor->id, 'expires_days' => 90]);
        Auth::forgetGuards();
        $this->travel(1)->seconds();
        $this->assertSame('stored', app(HttpMeasurementIngestion::class)->ingest($source, $token, ['observed_at' => now()->toISOString(), 'value' => '21', 'unit' => '°C']));
        $reading = Measurement::sole();
        $this->assertCount(2, $reading->assignmentContext->snapshot['locations']);
        $this->assertNull($reading->location_snapshot);
        $this->assertSame('recorded', $reading->provenance_snapshot['status']);
    }

    public function test_partial_grants_cannot_read_edit_ingest_or_discover_shared_hardware(): void
    {
        $other = Site::create(['name' => 'Private site']);
        $asset = $this->hardware([$this->placement(), $this->placement('site', $other->id)]);
        $sensor = $this->sensor($asset);
        $member = User::factory()->create(['permission_grants' => ['assets.manage', 'sensors.manage', 'integrations.manage'], 'location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($member)->getJson('/api/v1/assets')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sensors')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/assets/'.$asset->id, ['name' => 'Denied rename'])->assertNotFound();
        $this->postJson('/api/v1/integrations/http/sources', ['sensor_id' => $sensor->id, 'expires_days' => 90])->assertUnprocessable();
        $member->forceFill(['location_access' => [['type' => 'site', 'id' => $this->site->id], ['type' => 'site', 'id' => $other->id]]])->save();
        $this->actingAs($member->fresh())->getJson('/api/v1/assets')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reordered_duplicate_placements_are_noops_and_invalid_changes_rollback_atomically(): void
    {
        $asset = $this->hardware([$this->placement('room', $this->room->id), $this->placement()]);
        $this->actingAs($this->admin)->patchJson('/api/v1/assets/'.$asset->id, ['locations' => [$this->placement(), $this->placement('room', $this->room->id), $this->placement()]])->assertOk();
        $this->assertDatabaseCount('asset_assignments', 1);
        $this->assertDatabaseCount('asset_contexts', 1);
        $this->patchJson('/api/v1/assets/'.$asset->id, ['name' => 'Rejected', 'locations' => [['type' => 'plant', 'id' => $asset->id]]])->assertUnprocessable();
        $this->assertSame('Shared hardware', $asset->fresh()->name);
        $member = User::factory()->create(['permission_grants' => ['assets.manage'], 'location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($member)->patchJson('/api/v1/assets/'.$asset->id, ['name' => 'Rejected clear', 'locations' => []])->assertForbidden();
        $this->assertSame('Shared hardware', $asset->fresh()->name);
        $this->assertDatabaseCount('asset_locations', 2);
    }

    public function test_placement_changes_make_one_atomic_revision_and_preserve_previous_labels(): void
    {
        $asset = $this->hardware([$this->placement()]);
        $original = AssetContext::sole();
        $this->travel(1)->seconds();
        app(AssetAdministration::class)->save($this->admin, ['name' => 'New name', 'locations' => [$this->placement('room', $this->room->id), $this->placement()]], $asset);
        $this->assertDatabaseCount('asset_contexts', 2);
        $this->assertSame('Shared hardware', $original->fresh()->snapshot['asset']['name']);
        $this->assertCount(1, $original->fresh()->snapshot['locations']);
        $this->assertCount(2, AssetContext::orderByDesc('id')->firstOrFail()->snapshot['locations']);
        $this->assertDatabaseCount('asset_assignments', 2);
    }

    public function test_shared_subject_context_and_history_remain_private_after_reassignment(): void
    {
        $other = Site::create(['name' => 'Private subject site']);
        $asset = $this->hardware([$this->placement(), $this->placement('site', $other->id)]);
        $context = app(EventContext::class)->resolve(['type' => 'asset', 'id' => $asset->id]);
        $this->assertNull($context['subject_snapshot']['location']);
        $this->assertNotNull($context['subject_snapshot']['assetContextId']);
        $member = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->assertFalse(app(LocationAccess::class)->allowsSubject($member, $context['subject_snapshot']));
        app(AssetAdministration::class)->save($this->admin, ['locations' => [$this->placement()]], $asset);
        $this->actingAs($member)->getJson('/api/v1/assets/'.$asset->id.'/assignments')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_plant_only_assignments_are_visible_and_scoped_edits_preserve_hidden_shared_links(): void
    {
        $outside = Site::create(['name' => 'Other plant site']);
        $strain = Strain::create(['name' => 'Shared strain']);
        $plant = function (Site $site) use ($strain) {
            return app(PlantTracking::class)->create($this->admin, ['name' => 'Shared plant', 'strain_id' => $strain->id, 'request_id' => (string) Str::uuid(), 'location' => ['type' => 'site', 'id' => $site->id], 'planned' => true]);
        };
        $first = $plant($this->site);
        $second = $plant($outside);
        $shared = $this->hardware([]);
        $first->devices()->attach($shared);
        $member = User::factory()->create(['permission_grants' => ['plants.manage'], 'location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($member)->getJson('/api/v1/assets')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'data.0.plants');
        Auth::forgetGuards();
        $second->devices()->attach($shared);
        $this->actingAs($member)->getJson('/api/v1/assets')->assertOk()->assertJsonCount(0, 'data');
        Auth::forgetGuards();
        $visible = $this->hardware([$this->placement()]);
        $this->actingAs($member)->patchJson('/api/v1/plants/'.$first->id, ['version' => 1, 'device_ids' => [$visible->id]])->assertOk();
        $this->assertEqualsCanonicalizing([$shared->id, $visible->id], $first->devices()->withoutGlobalScopes()->pluck('assets.id')->all());
        $this->assertDatabaseHas('plant_device', ['plant_id' => $second->id, 'asset_id' => $shared->id]);
    }

    public function test_new_outside_assignments_revoke_source_credentials_and_diagnostics_agree(): void
    {
        $asset = $this->hardware([$this->placement()]);
        $sensor = $this->sensor($asset);
        $member = User::factory()->create(['permission_grants' => ['integrations.manage'], 'location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        [$source, $token] = app(HttpSourceAdministration::class)->apply($member, ['sensor_id' => $sensor->id, 'expires_days' => 90]);
        $outside = Site::create(['name' => 'Outside credential site']);
        app(AssetAdministration::class)->save($this->admin, ['locations' => [$this->placement(), $this->placement('site', $outside->id)]], $asset);
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/ingestion/http/'.$source->id, ['observed_at' => now()->toISOString(), 'value' => '20', 'unit' => '°C'])->assertUnauthorized();
        $diagnostics = app(SourceDiagnostics::class)->read($this->admin, ['kind' => 'http']);
        $this->assertSame(false, $diagnostics['data'][0]['credentialCurrent']);
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_revoked_actors_cannot_write_and_inactive_users_never_gain_point_access(): void
    {
        $asset = $this->hardware([$this->placement()]);
        $member = User::factory()->create(['is_active' => false, 'location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->assertFalse(app(LocationAccess::class)->record($member, $asset));
        User::whereKey($this->admin->id)->increment('session_version');
        $this->expectException(AuthenticationException::class);
        app(AssetAdministration::class)->save($this->admin, ['locations' => []], $asset);
    }
}
