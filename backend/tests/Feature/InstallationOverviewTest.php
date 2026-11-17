<?php

namespace Tests\Feature;

use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\Document;
use App\Models\EventEntry;
use App\Models\HomeAssistantLink;
use App\Models\HttpMeasurementSource;
use App\Models\InventoryItem;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\MqttMeasurementSource;
use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\InstallationOverview;
use App\Services\LifecycleDefinition;
use App\Services\PlantTracking;
use App\Services\SensorAdministration;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstallationOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-07T12:00:00Z'));
    }

    private function graph(): array
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Fictional Manual Device']);
        $type = MeasurementType::create(['name' => 'Fictional Type', 'code' => 'fictional_test_type', 'unit' => 'test']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => 'Fictional Probe', 'device_id' => $asset->id, 'measurement_type_id' => $type->id, 'channel_key' => 'probe']);
        $link = HomeAssistantLink::create(['sensor_id' => $sensor->id, 'instance_key' => 'fictional-instance-key', 'entity_id' => 'sensor.fictional_private_probe']);

        return [$admin, $asset, $sensor, $link];
    }

    public function test_guests_cannot_read_the_overview(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_members_and_administrators_receive_an_empty_private_summary(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $data = null;
        foreach ([$member, $admin] as $actor) {
            $response = $this->actingAs($actor)->getJson('/api/v1/dashboard')->assertOk();
            $response->assertJsonPath('data.generatedAt', '2026-11-07T12:00:00.000000Z')->assertJsonPath('data.assets.total', 0)->assertJsonPath('data.sensors.withoutReadings', 0)->assertJsonPath('data.alerts.active', 0)->assertJsonPath('data.inventory.lowStockItems', 0)->assertJsonPath('data.collection.lastAttemptAt', null)->assertJsonPath('data.collection.lastSuccessAt', null);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $data === null ? $data = $response->json('data') : $this->assertSame($data, $response->json('data'));
        }
        Http::assertNothingSent();
    }

    public function test_inactive_and_revoked_sessions_are_rejected_and_policy_is_enforced(): void
    {
        $inactive = User::factory()->create(['is_active' => false]);
        $this->assertFalse(Gate::forUser($inactive)->allows('dashboard.view'));
        $this->actingAs($inactive)->getJson('/api/v1/dashboard')->assertUnauthorized();
        $revoked = User::factory()->create(['session_version' => 1]);
        $this->actingAs($revoked)->withSession(['auth_version' => 0])->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_manual_hardware_disabled_sensors_and_historical_readings_are_counted_independently(): void
    {
        [$admin, $asset, $sensor, $link] = $this->graph();
        $equipment = app(AssetAdministration::class)->save($admin, ['name' => 'Fictional Equipment', 'status' => 'maintenance']);
        app(AssetAdministration::class)->save($admin, ['name' => 'Fictional Retired Device', 'status' => 'retired']);
        app(SensorAdministration::class)->save($admin, ['name' => 'Fictional Unlinked Probe', 'device_id' => $asset->id, 'measurement_type_id' => $sensor->measurement_type_id, 'channel_key' => 'manual', 'is_enabled' => false]);
        $site = Site::create(['name' => 'Fictional Site']);
        $room = $site->rooms()->create(['name' => 'Fictional Room']);
        $room->zones()->create(['name' => 'Fictional Zone']);
        foreach ([1, 2] as $day) {
            Measurement::create(['sensor_id' => $sensor->id, 'home_assistant_link_id' => $link->id, 'value' => '12.123456789', 'unit' => 'test', 'observed_at' => now()->subDays($day), 'received_at' => now()]);
        }
        $response = $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertOk();
        $response->assertJsonPath('data.locations', ['sites' => 1, 'rooms' => 1, 'zones' => 1]);
        $response->assertJsonPath('data.assets', ['total' => 3, 'active' => 1, 'maintenance' => 1, 'retired' => 1]);
        $response->assertJsonPath('data.sensors', ['total' => 2, 'enabled' => 1, 'withReadings' => 1, 'withoutReadings' => 1, 'linked' => 1, 'unlinked' => 1]);
        foreach (['Fictional Manual Device', 'sensor.fictional_private_probe', 'fictional-instance-key', '12.123456789', $admin->email, $admin->uuid, $sensor->id] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_collection_times_are_recorded_history_including_paused_links(): void
    {
        [$admin, $asset, $sensor, $link] = $this->graph();
        $link->forceFill(['is_enabled' => false, 'last_attempt_at' => '2026-11-07T11:50:00Z', 'last_success_at' => '2026-11-01T10:00:00Z'])->save();
        $before = $link->fresh()->getAttributes();
        $response = $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertOk();
        $response->assertJsonPath('data.collection', ['enabledLinks' => 0, 'pausedLinks' => 1, 'lastAttemptAt' => '2026-11-07T11:50:00.000000Z', 'lastSuccessAt' => '2026-11-01T10:00:00.000000Z']);
        $this->assertSame($before, $link->fresh()->getAttributes());
        $response->assertJsonMissingPath('data.collection.isHealthy')->assertJsonMissingPath('data.collection.url');
        Http::assertNothingSent();
    }

    public function test_only_active_alert_episodes_contribute_to_severity_and_acknowledgment_counts(): void
    {
        [$admin, $asset, $sensor] = $this->graph();
        foreach (['critical', 'warning', 'info', 'resolved'] as $kind) {
            $rule = AlertRule::create(['sensor_id' => $sensor->id, 'name' => 'Fictional Rule '.$kind, 'kind' => 'missing', 'severity' => $kind === 'resolved' ? 'critical' : $kind, 'delay_seconds' => 120, 'activated_at' => now()->subDay(), 'is_enabled' => $kind !== 'resolved']);
            AlertEpisode::create(['alert_rule_id' => $rule->id, 'rule_snapshot' => ['name' => 'Private snapshot'], 'severity' => $rule->severity, 'reason' => 'missing_reading', 'opened_at' => now()->subHour(), 'last_detected_at' => now()->subMinute(), 'acknowledged_at' => $kind === 'warning' ? now() : null, 'resolved_at' => $kind === 'resolved' ? now() : null, 'resolution' => $kind === 'resolved' ? 'recovered' : null]);
        }
        $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.alerts', ['active' => 3, 'unacknowledged' => 2, 'info' => 1, 'warning' => 1, 'critical' => 1, 'enabledRules' => 3]);
    }

    public function test_low_stock_uses_inclusive_configured_thresholds_and_excludes_archived_items(): void
    {
        $actor = User::factory()->create();
        foreach ([[0, null, false, 'kg'], [1000, 1000, false, 'l'], [0, 0, false, 'piece'], [2000, 1000, false, 'kg'], [0, 1000, true, 'piece']] as $i => [$total, $threshold, $archived, $unit]) {
            $item = InventoryItem::create(['name' => 'Fictional Stock '.$i, 'unit' => $unit, 'is_archived' => $archived]);
            $item->forceFill(['total_units' => $total, 'threshold_units' => $threshold])->save();
        }
        $this->actingAs($actor)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.inventory', ['activeItems' => 4, 'lowStockItems' => 2])->assertJsonMissingPath('data.inventory.totalQuantity');
    }

    public function test_refresh_reads_current_state_without_creating_business_records(): void
    {
        [$admin, $asset, $sensor, $link] = $this->graph();
        $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.collection.enabledLinks', 1);
        $link->forceFill(['is_enabled' => false])->save();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.collection.enabledLinks', 0)->assertJsonPath('data.collection.pausedLinks', 1);
        $this->assertDatabaseCount('measurements', 0);
        $this->assertDatabaseCount('alert_episodes', 0);
        $this->assertDatabaseCount('inventory_items', 0);
        Http::assertNothingSent();
    }

    public function test_service_rejects_an_actor_whose_session_was_revoked_after_loading(): void
    {
        $actor = User::factory()->create();
        User::whereKey($actor->id)->update(['session_version' => 1]);
        $this->expectException(AuthenticationException::class);
        app(InstallationOverview::class)->read($actor);
    }

    public function test_overview_reads_are_limited_per_account(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/dashboard')->assertOk();
        }
        $this->getJson('/api/v1/dashboard')->assertTooManyRequests();
    }

    public function test_culture_summary_separates_planned_and_archived_reuses_moisture_rules_and_deduplicates_due_plants(): void
    {
        [$admin, $asset, $sensor] = $this->graph();
        $site = Site::create(['name' => 'Fictional Culture Site']);
        $strain = Strain::create(['name' => 'Fictional Culture Strain']);
        Strain::create(['name' => 'Fictional Archived Strain', 'is_archived' => true]);
        $sensor->measurementType->update(['unit' => '%', 'nature' => 'substrate_moisture']);
        $sensor->update(['expected_interval_seconds' => 300]);
        Measurement::create(['sensor_id' => $sensor->id, 'value' => '60', 'unit' => '%', 'observed_at' => now(), 'received_at' => now()]);
        foreach (LifecycleDefinition::ids() as $phase) {
            $plant = app(PlantTracking::class)->create($admin, ['name' => 'Fictional '.$phase, 'request_id' => (string) Str::uuid(), 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id]]);
            $plant->forceFill(['phase' => $phase, 'started_at' => now()->subDays(5), 'phase_started_at' => now()->subDays(5),
                'phase_due_at' => $phase === 'maturation' ? null : (in_array($phase, ['growth', 'hanging_drying', 'curing']) ? now()->subDay() : now()->addDays(7)),
                'harvest_weight_grams' => match ($phase) {
                    'maturation' => '30.25', 'archived' => '45.50', default => null
                },
            ])->save();
            if ($phase === 'sowing') {
                $plant->devices()->attach($asset);
            }
        }
        $before = Plant::orderBy('id')->get()->toArray();
        $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.plants.total', 10)->assertJsonPath('data.plants.active', 8)
            ->assertJsonPath('data.plants.planned', 1)->assertJsonPath('data.plants.archived', 1)
            ->assertJsonPath('data.plants.lifecycleDue', 3)->assertJsonPath('data.plants.wateringDue', 3)
            ->assertJsonPath('data.plants.due', 5)->assertJsonPath('data.plants.harvested', 2)
            ->assertJsonPath('data.plants.harvestWeightGrams', '75.75')->assertJsonPath('data.plants.byPhase.curing', 1)
            ->assertJsonPath('data.strains', ['active' => 1, 'archived' => 1, 'inUse' => 1]);
        $this->assertSame($before, Plant::orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_new_overview_sections_respect_location_scope_and_count_each_linked_sensor_once(): void
    {
        [$admin, $asset, $sensor] = $this->graph();
        $site = Site::create(['name' => 'Fictional Accessible Site']);
        $outside = Site::create(['name' => 'Fictional Outside Site']);
        $this->placeAsset($asset, ['site_id' => $site->id]);
        $strain = Strain::create(['name' => 'Fictional Shared Strain']);
        foreach ([$site, $outside] as $place) {
            $plant = app(PlantTracking::class)->create($admin, ['name' => 'Fictional Scoped Plant', 'request_id' => (string) Str::uuid(), 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $place->id]]);
            $plant->forceFill(['harvest_weight_grams' => $place->is($site) ? '12.50' : '900.00'])->save();
            Document::forceCreate(['format' => 'pdf', 'uploader_uuid' => $admin->uuid, 'title' => 'Fictional Scoped File', 'original_name' => 'private.pdf', 'disk' => 'documents', 'path' => $place->id.'.pdf', 'media_type' => 'application/pdf', 'size_bytes' => 123, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $admin->id, 'site_id' => $place->id]);
            EventEntry::forceCreate(['author_uuid' => $admin->uuid, 'title' => 'Fictional Scoped Entry', 'body' => 'Private', 'category' => 'observation', 'severity' => 'info', 'occurred_at' => now(), 'created_by' => $admin->id, 'site_id' => $place->id]);
        }
        foreach ([$sensor, app(SensorAdministration::class)->save($admin, ['name' => 'Fictional HTTP Probe', 'device_id' => $asset->id, 'measurement_type_id' => $sensor->measurement_type_id, 'channel_key' => 'http'])] as $probe) {
            HttpMeasurementSource::create(['sensor_id' => $probe->id, 'issuer_id' => $admin->id, 'issuer_version' => $admin->session_version, 'expires_at' => now()->addDay(), 'last_received_at' => now()->subMinute()]);
        }
        MqttMeasurementSource::create(['sensor_id' => $sensor->id, 'instance_key' => 'private-instance', 'topic' => 'private/topic', 'is_enabled' => false, 'last_received_at' => now()->subHour()]);
        $hidden = app(AssetAdministration::class)->save($admin, ['name' => 'Fictional Outside Device', 'locations' => [['type' => 'site', 'id' => $outside->id]]]);
        $hiddenSensor = app(SensorAdministration::class)->save($admin, ['name' => 'Fictional Hidden Probe', 'device_id' => $hidden->id, 'measurement_type_id' => $sensor->measurement_type_id, 'channel_key' => 'hidden']);
        MqttMeasurementSource::create(['sensor_id' => $hiddenSensor->id, 'instance_key' => 'hidden-instance', 'topic' => 'hidden/topic']);
        $reader = User::factory()->create(['inventory_location_access' => [], 'location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $response = $this->actingAs($reader)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.plants.total', 1)->assertJsonPath('data.plants.harvestWeightGrams', '12.50')
            ->assertJsonPath('data.records.documents', 1)
            ->assertJsonPath('data.records.journalEntries', 1)
            ->assertJsonPath('data.records.recentJournalEntries', 1)
            ->assertJsonPath('data.sources.mqtt.total', 1)->assertJsonPath('data.sources.mqtt.enabled', 0)
            ->assertJsonPath('data.sources.http.total', 2)->assertJsonPath('data.sources.homeAssistant.total', 1)
            ->assertJsonPath('data.sensors.linked', 2)->assertJsonPath('data.sensors.unlinked', 0)
            ->assertJsonPath('data.inventory.lowStockItems', null);
        foreach (['private/topic', 'hidden/topic', 'private-instance', 'Fictional Outside', 'Private', $strain->id] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        Http::assertNothingSent();
    }
}
