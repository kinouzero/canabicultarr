<?php

namespace Tests\Feature;

use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\HomeAssistantLink;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\AssetAdministration;
use App\Services\MonitoringAlerts;
use App\Services\SensorAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitoringAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
    }

    private function graph(): array
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Test Device']);
        $type = MeasurementType::create(['name' => 'Test Type', 'code' => 'test_type', 'unit' => 'test']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => 'Test Probe', 'device_id' => $asset->id, 'measurement_type_id' => $type->id, 'channel_key' => 'probe']);

        return [$admin, $sensor];
    }

    private function payload(Sensor $sensor, array $data = []): array
    {
        return ['name' => 'Test Monitoring', 'kind' => 'threshold', 'sensor_id' => $sensor->id, 'severity' => 'warning', 'minimum' => '10', 'maximum' => '20', 'max_age_seconds' => 300, ...$data];
    }

    private function rule(User $admin, Sensor $sensor, array $data = []): AlertRule
    {
        return app(MonitoringAlerts::class)->save($admin, $this->payload($sensor, $data));
    }

    private function sample(Sensor $sensor, string $value = '25', int $age = 0): Measurement
    {
        $link = HomeAssistantLink::firstOrCreate(['sensor_id' => $sensor->id], ['instance_key' => hash('sha256', 'https://ha.example.test'), 'entity_id' => 'sensor.test']);

        return Measurement::create(['sensor_id' => $sensor->id, 'home_assistant_link_id' => $link->id, 'value' => $value, 'unit' => 'test', 'observed_at' => now()->subSeconds($age), 'received_at' => now()]);
    }

    private function evaluate(AlertRule $rule): AlertRule
    {
        return app(AlertEvaluator::class)->evaluate($rule);
    }

    public function test_guests_cannot_read_rules_episodes_or_acknowledge(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $this->sample($sensor);
        $this->evaluate($rule);
        $episode = AlertEpisode::sole();
        foreach (['/api/v1/alerts', '/api/v1/alert-rules', '/api/v1/alert-rules/'.$rule->id] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }$this->postJson('/api/v1/alerts/'.$episode->id.'/acknowledge')->assertUnauthorized();
        $this->postJson('/api/v1/alert-rules', $this->payload($sensor))->assertUnauthorized();
    }

    public function test_members_read_and_acknowledge_but_only_admins_manage_or_evaluate(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $this->sample($sensor);
        $this->evaluate($rule);
        $episode = AlertEpisode::sole();
        $member = User::factory()->create();
        $this->actingAs($member)->getJson('/api/v1/alert-rules')->assertOk()->assertJsonPath('data.0.canManage', false);
        $this->postJson('/api/v1/alert-rules', $this->payload($sensor))->assertForbidden();
        $this->patchJson('/api/v1/alert-rules/'.$rule->id, ['version' => 1, 'name' => 'Denied'])->assertForbidden();
        $this->postJson('/api/v1/alert-rules/'.$rule->id.'/evaluate')->assertForbidden();
        $this->postJson('/api/v1/alerts/'.$episode->id.'/acknowledge')->assertOk()->assertJsonPath('data.acknowledger.id', $member->uuid)->assertJsonPath('data.resolvedAt', null)->assertJsonMissingPath('data.acknowledger.email');
    }

    public function test_rule_creation_assigns_identity_and_server_controlled_fields(): void
    {
        [$admin,$sensor] = $this->graph();
        $data = $this->actingAs($admin)->postJson('/api/v1/alert-rules', $this->payload($sensor, ['minimum' => '1e1', 'version' => 999, 'last_result' => 'alerting', 'activated_at' => '2000-01-01', 'unit' => 'forged']))->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.minimum', '10.000000000')->assertJsonPath('data.lastResult', 'waiting')->json('data');
        $this->assertTrue(Str::isUuid($data['id']));
        $this->assertDatabaseCount('alert_episodes', 0);
        Http::assertNothingSent();
    }

    public function test_bounds_and_intervals_are_validated_and_missing_targets_are_rejected(): void
    {
        [$admin,$sensor] = $this->graph();
        $this->actingAs($admin);
        foreach ([['minimum' => null, 'maximum' => null], ['minimum' => '30', 'maximum' => '20'], ['minimum' => 'NaN'], ['max_age_seconds' => 0], ['max_age_seconds' => 86401], ['kind' => 'other'], ['minimum' => 1.5]] as $data) {
            $this->postJson('/api/v1/alert-rules', $this->payload($sensor, $data))->assertUnprocessable();
        }
        $this->postJson('/api/v1/alert-rules', $this->payload($sensor, ['sensor_id' => (string) Str::uuid()]))->assertNotFound();
        $this->assertDatabaseCount('alert_rules', 0);
    }

    public function test_kind_and_sensor_identity_are_immutable_and_versions_protect_edits(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $url = '/api/v1/alert-rules/'.$rule->id;
        $this->actingAs($admin)->patchJson($url, ['version' => 1, 'kind' => 'missing'])->assertUnprocessable();
        $this->patchJson($url, ['version' => 1, 'name' => 'Changed'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($url, ['version' => 1, 'name' => 'Stale'])->assertConflict();
        $this->patchJson($url, ['version' => 2, 'name' => 'Changed'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($url, ['name' => 'No version'])->assertUnprocessable();
    }

    public function test_outside_band_opens_one_episode_and_repeated_checks_preserve_first_observation(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $first = $this->sample($sensor);
        $this->assertSame('alerting', $this->evaluate($rule)->last_result);
        $episode = AlertEpisode::sole();
        $this->assertSame('above_maximum', $episode->reason);
        $this->travel(1)->seconds();
        $second = $this->sample($sensor, '26');
        $this->evaluate($rule);
        $this->assertDatabaseCount('alert_episodes', 1);
        $this->assertSame($first->id, $episode->fresh()->first_observation['id']);
        $this->assertSame($second->id, $episode->fresh()->last_observation['id']);
        $this->assertNull($episode->fresh()->resolved_at);
    }

    public function test_exact_bounds_are_normal_and_recovery_preserves_history_then_rearms(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $this->sample($sensor, '20');
        $this->assertSame('normal', $this->evaluate($rule)->last_result);
        $this->assertDatabaseCount('alert_episodes', 0);
        $this->travel(1)->seconds();
        $this->sample($sensor, '9.999999999');
        $this->evaluate($rule);
        $episode = AlertEpisode::sole();
        $this->assertSame('below_minimum', $episode->reason);
        $this->travel(1)->seconds();
        $this->sample($sensor, '10');
        $this->assertSame('normal', $this->evaluate($rule)->last_result);
        $this->assertSame('recovered', $episode->fresh()->resolution);
        $this->assertSame('10.000000000', $episode->fresh()->resolution_observation['value']);
        $this->travel(1)->seconds();
        $this->sample($sensor, '21');
        $this->evaluate($rule);
        $this->assertDatabaseCount('alert_episodes', 2);
        $this->assertSame(1, AlertEpisode::whereNull('resolved_at')->count());
    }

    public function test_missing_or_stale_threshold_data_does_not_falsely_resolve_an_active_episode(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $this->assertSame('waiting', $this->evaluate($rule)->last_result);
        $this->sample($sensor);
        $this->evaluate($rule);
        $episode = AlertEpisode::sole();
        $this->travel(301)->seconds();
        $this->assertSame('waiting', $this->evaluate($rule)->last_result);
        $this->assertNull($episode->fresh()->resolved_at);
        $this->assertDatabaseCount('alert_episodes', 1);
    }

    public function test_missing_report_rule_honors_activation_grace_and_recovers_on_new_data(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor, ['kind' => 'missing', 'delay_seconds' => 120, 'minimum' => null, 'maximum' => null, 'max_age_seconds' => null]);
        $this->assertSame('normal', $this->evaluate($rule)->last_result);
        $this->travel(119)->seconds();
        $this->assertSame('normal', $this->evaluate($rule)->last_result);
        $this->travel(1)->seconds();
        $this->assertSame('alerting', $this->evaluate($rule)->last_result);
        $this->assertSame('missing_reading', AlertEpisode::sole()->reason);
        $this->sample($sensor);
        $this->evaluate($rule);
        $this->assertSame('recovered', AlertEpisode::sole()->resolution);
        $this->travel(121)->seconds();
        $this->evaluate($rule);
        $this->assertDatabaseCount('alert_episodes', 2);
    }

    public function test_old_existing_reading_does_not_skip_new_rule_grace(): void
    {
        [$admin,$sensor] = $this->graph();
        $this->sample($sensor, '15', 3600);
        $rule = $this->rule($admin, $sensor, ['kind' => 'missing', 'delay_seconds' => 120]);
        $this->assertSame('normal', $this->evaluate($rule)->last_result);
        $this->travel(120)->seconds();
        $this->evaluate($rule);
        $this->assertSame('overdue_reading', AlertEpisode::sole()->reason);
    }

    public function test_paused_disabled_and_retired_sources_are_suppressed(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $this->sample($sensor);
        $this->evaluate($rule);
        $link = HomeAssistantLink::sole();
        $link->update(['is_enabled' => false]);
        $this->assertSame('suppressed', $this->evaluate($rule)->last_result);
        $this->assertSame('source_disabled', AlertEpisode::sole()->resolution);
        $link->update(['is_enabled' => true]);
        $sensor->update(['is_enabled' => false]);
        $this->assertSame('suppressed', $this->evaluate($rule)->last_result);
        $sensor->update(['is_enabled' => true]);
        $sensor->device->update(['status' => 'retired']);
        $this->assertSame('suppressed', $this->evaluate($rule)->last_result);
        $this->assertDatabaseCount('alert_episodes', 1);
    }

    public function test_definition_changes_resolve_old_episode_without_rewriting_its_snapshot(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $this->sample($sensor);
        $this->evaluate($rule);
        $episode = AlertEpisode::sole();
        app(MonitoringAlerts::class)->save($admin, ['version' => 1, 'name' => 'New name', 'maximum' => '30'], $rule);
        $this->assertSame('rule_changed', $episode->fresh()->resolution);
        $this->assertSame('Test Monitoring', $episode->fresh()->rule_snapshot['name']);
        $this->assertSame('20.000000000', $episode->fresh()->rule_snapshot['maximum']);
        $this->assertSame('normal', $this->evaluate($rule)->last_result);
    }

    public function test_disabling_a_rule_resolves_it_immediately_and_preserves_history(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $this->sample($sensor);
        $this->evaluate($rule);
        app(MonitoringAlerts::class)->save($admin, ['version' => 1, 'is_enabled' => false], $rule);
        $this->assertSame('rule_disabled', AlertEpisode::sole()->resolution);
        $this->assertSame('disabled', $rule->fresh()->last_result);
        $this->evaluate($rule);
        $this->assertDatabaseCount('alert_episodes', 1);
    }

    public function test_acknowledgment_is_idempotent_and_never_resolves_the_episode(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $this->sample($sensor);
        $this->evaluate($rule);
        $episode = AlertEpisode::sole();
        $first = User::factory()->create();
        $second = User::factory()->create();
        app(MonitoringAlerts::class)->acknowledge($first, $episode);
        $this->travel(1)->seconds();
        $result = app(MonitoringAlerts::class)->acknowledge($second, $episode);
        $this->assertSame($first->uuid, $result->acknowledger_uuid);
        $this->assertNull($result->resolved_at);
        $this->evaluate($rule);
        $this->assertDatabaseCount('alert_episodes', 1);
    }

    public function test_filters_pagination_and_private_account_fields(): void
    {
        [$admin,$sensor] = $this->graph();
        for ($i = 0; $i < 22; $i++) {
            $rule = $this->rule($admin, $sensor, ['name' => 'Rule '.$i]);
            $this->evaluate($rule);
        }$this->sample($sensor);
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->actingAs($admin)->getJson('/api/v1/alerts')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 22);
        $this->getJson('/api/v1/alerts?page=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/alerts?state=resolved')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/alerts?severity=critical')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/alert-rules?search=Rule%2021')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/alerts?page=0')->assertUnprocessable();
    }

    public function test_fresh_role_and_session_checks_protect_writes(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $admin->fresh()->permissionRoles()->sync([AccessProfileSeeder::defaultProfile()->id]);
        try {
            app(MonitoringAlerts::class)->save($admin, ['version' => 1, 'name' => 'Denied'], $rule);
            $this->fail('Stale admin accepted');
        } catch (AuthorizationException) {
            $this->assertSame('Test Monitoring', $rule->fresh()->name);
        }
        $member = User::factory()->create(['is_active' => false]);
        try {
            $this->sample($sensor);
            $this->evaluate($rule);
            app(MonitoringAlerts::class)->acknowledge($member, AlertEpisode::sole());
            $this->fail('Inactive account accepted');
        } catch (AuthorizationException) {
            $this->assertNull(AlertEpisode::sole()->acknowledged_at);
        }
    }

    public function test_open_episode_uniqueness_and_reference_foreign_keys_are_enforced(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $this->sample($sensor);
        $this->evaluate($rule);
        $episode = AlertEpisode::sole();
        foreach ([$rule, $sensor] as $record) {
            try {
                DB::transaction(fn () => $record->delete());
                $this->fail('Referenced deletion accepted');
            } catch (QueryException) {
                $this->assertTrue($record->newQuery()->whereKey($record->id)->exists());
            }
        }
        try {
            DB::transaction(fn () => AlertEpisode::create(['alert_rule_id' => $rule->id, 'rule_snapshot' => $episode->rule_snapshot, 'severity' => 'warning', 'reason' => 'above_maximum', 'opened_at' => now(), 'last_detected_at' => now()]));
            $this->fail('Duplicate active episode accepted');
        } catch (QueryException) {
            $this->assertDatabaseCount('alert_episodes', 1);
        }
    }

    public function test_evaluation_cache_lock_and_audit_logs_omit_values_and_labels(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = $this->rule($admin, $sensor);
        $this->sample($sensor);
        Log::spy();
        $this->evaluate($rule);
        $episode = AlertEpisode::sole();
        Log::shouldHaveReceived('notice')->with('alert.opened', ['rule_uuid' => $rule->id, 'episode_uuid' => $episode->id, 'sensor_uuid' => $sensor->id, 'reason' => 'above_maximum'])->once();
        $lock = Cache::lock('monitoring-alerts.evaluate', 300);
        $this->assertTrue($lock->get());
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $lock->release();
        Http::assertNothingSent();
    }
}
