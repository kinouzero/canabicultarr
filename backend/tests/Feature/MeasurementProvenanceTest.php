<?php

namespace Tests\Feature;

use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\AssetContext;
use App\Models\HttpMeasurementSource;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Plant;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\HttpMeasurementIngestion;
use App\Services\HttpSourceAdministration;
use App\Services\LocationAccess;
use App\Services\PlantTracking;
use App\Services\SensorReporting;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeasurementProvenanceTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    private Site $site;

    private Asset $asset;

    private Sensor $sensor;

    private HttpMeasurementSource $source;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo(now()->startOfSecond());
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->site = Site::create(['name' => 'Original site']);
        $this->asset = new Asset(['name' => 'Original hardware']);
        $this->placeAsset($this->asset, ['site_id' => $this->site->id]);
        $type = MeasurementType::create(['name' => 'Temperature', 'code' => 'provenance', 'unit' => '°C']);
        $this->sensor = new Sensor(['name' => 'Original channel', 'channel_key' => 'temperature']);
        $this->sensor->asset_id = $this->asset->id;
        $this->sensor->measurement_type_id = $type->id;
        $this->sensor->save();
        [$this->source, $this->token] = app(HttpSourceAdministration::class)->apply($this->admin, ['sensor_id' => $this->sensor->id, 'expires_days' => 90]);
    }

    protected function tearDown(): void
    {
        // Only the guarded isolated test database; older down migrations reject live samples.
        DB::table('measurements')->delete();
        DB::table('plant_events')->delete();
        DB::table('plant_device')->delete();
        DB::table('plants')->delete();
        parent::tearDown();
    }

    private function sample(string $time, string $value = '20'): Measurement
    {
        Auth::forgetGuards();
        $result = app(HttpMeasurementIngestion::class)->ingest($this->source, $this->token, ['value' => $value, 'unit' => '°C', 'observed_at' => $time]);
        $this->assertSame('stored', $result);

        return Measurement::where('observed_at', CarbonImmutable::parse($time)->format('Y-m-d H:i:s.u'))->firstOrFail();
    }

    private function plant(Site $site): Plant
    {
        return app(PlantTracking::class)->create($this->admin, ['name' => 'Assigned plant', 'request_id' => (string) Str::uuid(), 'strain_id' => Strain::firstOrCreate(['name' => 'Historical strain'])->id, 'location' => ['type' => 'site', 'id' => $site->id], 'planned' => true]);
    }

    public function test_delayed_and_out_of_order_samples_use_observation_time_and_replay_keeps_provenance(): void
    {
        $original = AssetContext::firstOrFail();
        $this->travel(10)->seconds();
        $oldTime = now()->toISOString();
        $this->travel(10)->seconds();
        $destination = Site::create(['name' => 'Destination site']);
        $this->placeAsset($this->asset, ['site_id' => $destination->id]);
        $newContext = AssetContext::orderByDesc('id')->firstOrFail();
        $this->travel(10)->seconds();
        $newTime = now()->toISOString();
        $new = $this->sample($newTime, '30');
        $old = $this->sample($oldTime);
        $this->assertSame($original->id, $old->asset_context_id);
        $this->assertSame($this->site->id, $old->location_snapshot['site']['id']);
        $this->assertSame($newContext->id, $new->asset_context_id);
        $this->assertSame($destination->id, $new->location_snapshot['site']['id']);
        $attributes = $old->getAttributes();
        $this->asset->update(['name' => 'Renamed hardware']);
        $this->assertSame('duplicate', app(HttpMeasurementIngestion::class)->ingest($this->source, $this->token, ['value' => '20', 'unit' => '°C', 'observed_at' => $oldTime]));
        $this->assertSame($attributes, $old->fresh()->getAttributes());
        $this->assertSame('Original hardware', $old->fresh()->assignmentContext->snapshot['asset']['name']);
        $this->assertSame($this->source->id, $old->provenance_snapshot['source']['id']);
        $this->assertSame($this->asset->id, $old->provenance_snapshot['assetId']);
        $this->assertSame($this->sensor->id, $old->provenance_snapshot['sensorId']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_samples_before_the_baseline_or_in_the_future_have_explicit_unknown_assignment(): void
    {
        foreach ([now()->subMinute()->toISOString(), now()->addMinute()->toISOString()] as $time) {
            $reading = $this->sample($time);
            $this->assertNull($reading->asset_context_id);
            $this->assertNull($reading->location_snapshot);
            $this->assertSame('unknown', $reading->provenance_snapshot['status']);
        }
        $member = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($member)->getJson('/api/v1/sensors/'.$this->sensor->id.'/measurements')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/sensors/'.$this->sensor->id.'/measurements')->assertOk()->assertJsonPath('data.0.provenance.status', 'unknown');
    }

    public function test_plant_link_move_unlink_and_deletion_leave_immutable_assignment_contexts(): void
    {
        $plant = $this->plant($this->site);
        $this->travel(1)->seconds();
        $plant->devices()->attach($this->asset);
        $this->travel(1)->seconds();
        $linked = $this->sample(now()->toISOString());
        $this->assertSame($plant->id, $linked->assignmentContext->snapshot['plants'][0]['id']);
        $this->travel(1)->seconds();
        $plant->forceFill(['site_id' => Site::create(['name' => 'Other plant site'])->id])->save();
        $this->travel(1)->seconds();
        $moved = $this->sample(now()->toISOString());
        $this->assertNotSame($linked->asset_context_id, $moved->asset_context_id);
        $this->assertSame($this->site->id, $linked->fresh()->assignmentContext->snapshot['plants'][0]['location']['site']['id']);
        $this->travel(1)->seconds();
        $plant->devices()->detach();
        app(PlantTracking::class)->delete($this->admin, $plant, $plant->version);
        $this->travel(1)->seconds();
        $unlinked = $this->sample(now()->toISOString());
        $this->assertSame([], $unlinked->assignmentContext->snapshot['plants']);
        $this->assertSame($plant->id, $linked->fresh()->assignmentContext->snapshot['plants'][0]['id']);
    }

    public function test_a_partial_geographic_grant_never_reveals_shared_assignment_names_or_values(): void
    {
        $outside = Site::create(['name' => 'Private site']);
        $plant = $this->plant($outside);
        $plant->devices()->attach($this->asset);
        $this->travel(1)->seconds();
        $this->sample(now()->toISOString());
        $member = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $response = $this->actingAs($member)->getJson('/api/v1/sensors/'.$this->sensor->id.'/measurements')->assertNotFound();
        $this->assertStringNotContainsString('Private site', $response->getContent());
        Auth::forgetGuards();
        $member->forceFill(['location_access' => [['type' => 'site', 'id' => $this->site->id], ['type' => 'site', 'id' => $outside->id]]])->save();
        $this->actingAs($member->fresh())->getJson('/api/v1/sensors/'.$this->sensor->id.'/measurements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.provenance.assignments.plants.0.id', $plant->id);
    }

    public function test_metadata_noops_and_transaction_rollbacks_do_not_rewrite_or_append_committed_contexts(): void
    {
        $count = AssetContext::count();
        $this->asset->update(['description' => 'Metadata only']);
        $this->assertSame($count, AssetContext::count());
        DB::beginTransaction();
        $this->asset->update(['name' => 'Rolled back name']);
        $this->assertSame($count + 1, AssetContext::count());
        DB::rollBack();
        $this->assertSame($count, AssetContext::count());
        $this->assertSame('Original hardware', $this->asset->fresh()->name);
    }

    public function test_export_uses_frozen_names_and_explicit_provenance_and_legacy_remains_uninvented(): void
    {
        $this->travel(1)->seconds();
        $reading = $this->sample(now()->toISOString());
        $this->asset->update(['name' => 'New hardware name']);
        $this->sensor->update(['name' => 'New channel name']);
        $result = app(SensorReporting::class)->export($this->admin, $this->sensor, []);
        $csv = stream_get_contents($result['stream']);
        fclose($result['stream']);
        $this->assertStringContainsString('Original hardware', $csv);
        $this->assertStringContainsString('Original channel', $csv);
        $this->assertStringContainsString('assignment_revision_id', $csv);
        $this->assertStringContainsString($this->source->id, $csv);
        $this->assertStringContainsString('recorded', $csv);
        $migration = require database_path('migrations/2026_11_15_100000_add_measurement_assignment_provenance.php');
        try {
            $migration->down();
            $this->fail('Recorded provenance must not be dropped.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Retain recorded measurement provenance', $error->getMessage());
        }
        $this->assertSame('recorded', $reading->fresh()->provenance_snapshot['status']);
    }

    public function test_migration_preserves_existing_legacy_snapshots_without_backfilling_unknown_identity(): void
    {
        $reading = Measurement::create(['sensor_id' => $this->sensor->id, 'http_measurement_source_id' => $this->source->id, 'value' => '12', 'unit' => '°C', 'observed_at' => now(), 'received_at' => now(), 'location_snapshot' => $this->asset->locationDetails()]);
        $attributes = $reading->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_11_15_100000_add_measurement_assignment_provenance.php');
        $this->reapplySchemaFrom('2026_11_15_100000');
        $row = (array) DB::table('measurements')->first();
        foreach (['id', 'sensor_id', 'observed_at', 'received_at', 'value', 'location_snapshot'] as $field) {
            $this->assertEquals($attributes[$field], $row[$field]);
        }
        $this->assertNull($reading->fresh()->provenance_snapshot);
        $this->assertNull($reading->fresh()->asset_context_id);
    }

    public function test_linking_a_new_plant_never_reuses_another_plants_latest_reading(): void
    {
        $original = $this->plant($this->site);
        $original->devices()->attach($this->asset);
        $this->travel(1)->seconds();
        $this->sample(now()->toISOString());
        $original->devices()->detach();
        $replacement = $this->plant($this->site);
        $replacement->devices()->attach($this->asset);
        $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$replacement->id)->assertOk()->assertJsonPath('data.measurements.0.value', null);
        $this->travel(1)->seconds();
        $this->sample(now()->toISOString(), '31');
        $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$replacement->id)->assertOk()->assertJsonPath('data.measurements.0.value', '31.000000000');
    }

    public function test_alerts_retain_assignment_access_and_do_not_mix_observations_after_a_move(): void
    {
        $outside = Site::create(['name' => 'Restricted plant site']);
        $plant = $this->plant($outside);
        $plant->devices()->attach($this->asset);
        $this->travel(1)->seconds();
        $this->sample(now()->toISOString());
        $rule = AlertRule::create(['sensor_id' => $this->sensor->id, 'name' => 'Provenance threshold', 'kind' => 'threshold', 'severity' => 'warning', 'maximum' => '10', 'max_age_seconds' => 600, 'activated_at' => now()]);
        app(AlertEvaluator::class)->evaluate($rule);
        $episode = $rule->episodes()->firstOrFail();
        $member = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($member);
        $this->assertSame(0, AlertEpisode::count());
        $this->assertFalse(app(LocationAccess::class)->record($member, $episode));
        Auth::forgetGuards();
        $this->travel(1)->seconds();
        $plant->devices()->detach();
        $this->travel(1)->seconds();
        $this->sample(now()->toISOString(), '30');
        app(AlertEvaluator::class)->evaluate($rule);
        $this->assertSame('assignment_changed', $episode->fresh()->resolution);
        $this->assertSame('20.000000000', $episode->fresh()->last_observation['value']);
        $this->assertSame(2, $rule->episodes()->count());
        $this->actingAs($member);
        $this->assertSame(1, AlertEpisode::count());
    }

    public function test_the_first_recorded_sample_cannot_update_an_episode_with_incomplete_legacy_origin(): void
    {
        $rule = AlertRule::create(['sensor_id' => $this->sensor->id, 'name' => 'Legacy threshold', 'kind' => 'threshold', 'severity' => 'warning', 'maximum' => '10', 'max_age_seconds' => 600, 'activated_at' => now()]);
        $legacy = $rule->episodes()->create(['rule_snapshot' => ['location' => $this->asset->locationDetails()], 'severity' => 'warning', 'reason' => 'above_maximum', 'opened_at' => now(), 'last_detected_at' => now(), 'last_observation' => ['value' => '99']]);
        $this->travel(1)->seconds();
        $this->sample(now()->toISOString());
        app(AlertEvaluator::class)->evaluate($rule);
        $this->assertSame('assignment_changed', $legacy->fresh()->resolution);
        $this->assertSame('99', $legacy->fresh()->last_observation['value']);
        $this->assertSame(2, $rule->episodes()->count());
        $this->assertSame('recorded', $rule->episodes()->whereNull('resolved_at')->sole()->rule_snapshot['provenanceStatus']);
    }
}
