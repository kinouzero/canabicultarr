<?php

namespace Tests\Feature;

use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\PlantTracking;
use App\Services\SensorAdministration;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class InsightOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00Z'));
    }

    private function sensor(User $admin, string $name, ?Site $site = null, ?int $interval = 60, bool $enabled = true)
    {
        $asset = app(AssetAdministration::class)->save($admin, ['name' => $name.' device', ...($site ? ['locations' => [['type' => 'site', 'id' => $site->id]]] : [])]);
        $type = MeasurementType::firstOrCreate(['code' => 'test_insights'], ['name' => 'Test insights', 'unit' => '%', 'nature' => 'substrate_moisture']);

        return app(SensorAdministration::class)->save($admin, ['name' => $name, 'device_id' => $asset->id, 'measurement_type_id' => $type->id, 'channel_key' => 'probe', 'expected_interval_seconds' => $interval, 'is_enabled' => $enabled]);
    }

    private function sample($sensor, string $when, ?Site $site = null): void
    {
        Measurement::create(['sensor_id' => $sensor->id, 'value' => '42', 'unit' => '%', 'observed_at' => $when, 'received_at' => now(), 'location_snapshot' => $site ? ['type' => 'site', 'id' => $site->id, 'site' => ['id' => $site->id, 'name' => $site->name], 'room' => null, 'zone' => null] : null]);
    }

    private function plant(User $admin, Site $site, string $phase, ?string $due = null): Plant
    {
        $strain = Strain::firstOrCreate(['name_key' => 'insights strain'], ['name' => 'Insights strain']);
        $plant = app(PlantTracking::class)->create($admin, ['name' => 'Insights '.$phase, 'request_id' => (string) Str::uuid(), 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id]]);
        $plant->forceFill(['phase' => $phase, 'started_at' => $phase === 'planned' ? null : now()->subDays(10), 'phase_due_at' => $due])->save();

        return $plant;
    }

    public function test_insights_require_an_active_session_and_validate_filters(): void
    {
        foreach (['measurements/overview', 'culture/overview'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertUnauthorized();
        }
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/measurements/overview?status=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/measurements/overview?sensor_ids[]=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/culture/overview?days=100')->assertUnprocessable();
        $user->forceFill(['is_active' => false])->save();
        $this->getJson('/api/v1/measurements/overview')->assertUnauthorized();
        $this->getJson('/api/v1/culture/overview')->assertUnauthorized();
    }

    public function test_measurement_page_hydrates_only_visible_channels_as_the_catalog_grows(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $sensor = $this->sensor($admin, 'Scale fixture');
        $template = $sensor->getAttributes();
        $rows = [];
        for ($i = 0; $i < 419; $i++) {
            $rows[] = [...$template, 'id' => (string) Str::uuid(), 'name' => 'Scale '.$i, 'name_key' => 'scale '.$i, 'channel_key' => 'scale_'.$i];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('sensors')->insert($chunk);
        }
        $hydrated = 0;
        Sensor::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        $this->actingAs($admin)->getJson('/api/v1/measurements/overview?per_page=20')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 420);
        $this->assertLessThanOrEqual(40, $hydrated);
    }

    public function test_measurement_health_uses_latest_observation_excludes_future_and_keeps_prefilter_counts(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        foreach (['fresh', 'stale', 'missing', 'unknown', 'disabled'] as $status) {
            $sensor = $this->sensor($admin, 'Insights '.$status, interval: $status === 'unknown' ? null : 60, enabled: $status !== 'disabled');
            if ($status !== 'missing') {
                $this->sample($sensor, $status === 'stale' ? '2026-11-09T11:57:59Z' : '2026-11-09T11:59:00Z');
            }
            if ($status === 'stale') {
                $this->sample($sensor, '2026-11-09T13:00:00Z');
            }
        }
        $result = $this->actingAs($admin)->getJson('/api/v1/measurements/overview?status=stale')->assertOk()->assertJsonPath('summary.total', 5)->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data')->assertJsonPath('data.0.sensor.measurementType.nature', 'substrate_moisture')->assertJsonPath('data.0.status', 'stale')->assertJsonPath('data.0.latest.observedAt', '2026-11-09T11:57:59.000000Z');
        foreach (['fresh', 'stale', 'missing', 'unknown', 'disabled'] as $status) {
            $result->assertJsonPath('summary.'.$status, 1);
        }
        $this->assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public function test_measurement_pagination_and_favorites_filter_before_counting(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $last = null;
        for ($i = 0; $i < 22; $i++) {
            $last = $this->sensor($admin, sprintf('Channel %02d', $i));
        }
        $this->actingAs($admin)->getJson('/api/v1/measurements/overview?page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 22)->assertJsonPath('data.0.sensor.name', 'Channel 20');
        $this->getJson('/api/v1/measurements/overview?pinned_only=1&sensor_ids[]='.$last->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.total', 1);
        $this->getJson('/api/v1/measurements/overview?pinned_only=1')->assertOk()->assertJsonPath('summary.total', 0);
    }

    public function test_site_scope_protects_current_channels_and_their_historical_measurements(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Visible insights site']);
        $hidden = Site::create(['name' => 'Hidden insights site']);
        $visible = $this->sensor($admin, 'Visible channel', $site);
        $secret = $this->sensor($admin, 'Hidden channel', $hidden);
        $this->sample($visible, '2026-11-09T11:59:00Z', $hidden);
        $this->sample($secret, '2026-11-09T11:59:00Z', $hidden);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $response = $this->actingAs($reader)->getJson('/api/v1/measurements/overview')->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.status', 'missing')->assertJsonPath('data.0.latest', null);
        $this->assertStringNotContainsString('Hidden', $response->getContent());
        $this->getJson('/api/v1/measurements/overview?site_id='.$hidden->id)->assertOk()->assertJsonPath('summary.total', 0);
    }

    public function test_culture_counts_unique_attention_preserves_yield_and_filters_activity_by_site_and_period(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Visible culture']);
        $hidden = Site::create(['name' => 'Hidden culture']);
        $active = $this->plant($admin, $site, 'growth', '2026-11-09T11:00:00Z');
        $this->plant($admin, $site, 'planned');
        $archived = $this->plant($admin, $site, 'archived');
        $archived->forceFill(['harvest_weight_grams' => '12.50'])->save();
        $this->plant($admin, $hidden, 'growth');
        foreach (['2026-11-09T11:00:00Z', '2026-11-01T11:00:00Z'] as $index => $date) {
            PlantEvent::create(['plant_id' => $active->id, 'plant_version' => 10 + $index, 'kind' => 'maintained', 'to_phase' => 'growth', 'snapshot' => ['location' => $active->locationDetails()], 'fields' => [], 'occurred_at' => $date, 'recorded_at' => now()]);
        }
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $result = $this->actingAs($reader)->getJson('/api/v1/culture/overview')->assertOk()->assertJsonPath('data.plants.total', 3)->assertJsonPath('data.plants.active', 1)->assertJsonPath('data.plants.planned', 1)->assertJsonPath('data.plants.archived', 1)->assertJsonPath('data.plants.due', 1)->assertJsonPath('data.plants.wateringDue', 1)->assertJsonPath('data.plants.lifecycleDue', 1)->assertJsonPath('data.plants.harvestWeightGrams', '12.50')->assertJsonCount(7, 'data.activity')->assertJsonPath('data.activity.6.maintenance', 1)->assertJsonCount(1, 'data.upcoming')->assertJsonPath('data.locations.0.active', 1);
        $this->assertStringNotContainsString('Hidden culture', $result->getContent());
        $this->getJson('/api/v1/culture/overview?days=30')->assertOk()->assertJsonCount(30, 'data.activity');
        $this->getJson('/api/v1/culture/overview?site_id='.$hidden->id)->assertNotFound();
    }

    public function test_history_health_matches_the_overview_and_never_uses_future_samples(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $sensor = $this->sensor($admin, 'History health');
        $this->sample($sensor, '2026-11-09T11:00:00Z');
        $this->sample($sensor, '2026-11-09T13:00:00Z');
        $this->actingAs($admin)->getJson('/api/v1/sensors/'.$sensor->id.'/measurements')->assertOk()->assertJsonPath('summary.status', 'stale')->assertJsonPath('summary.latest.observedAt', '2026-11-09T11:00:00.000000Z');
        $sensor->device->forceFill(['status' => 'maintenance'])->save();
        $this->getJson('/api/v1/sensors/'.$sensor->id.'/measurements')->assertOk()->assertJsonPath('summary.status', 'disabled');
    }
}
