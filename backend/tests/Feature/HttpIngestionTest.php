<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\HomeAssistantLink;
use App\Models\HttpMeasurementSource;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\HttpSourceAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HttpIngestionTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo(now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        // Only isolated test databases can reach this fixture class (Tests\TestCase guard).
        Measurement::whereNotNull('http_measurement_source_id')->delete();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function sensor(?string $unit = 'test'): Sensor
    {
        $asset = Asset::create(['name' => 'Fictional Manual Device', 'status' => 'active']);
        $type = MeasurementType::create(['name' => 'Fictional HTTP Type', 'code' => 'http_'.bin2hex(random_bytes(5)), 'unit' => $unit, 'decimal_places' => 3]);
        $sensor = new Sensor(['name' => 'Fictional HTTP Probe', 'channel_key' => 'probe', 'is_enabled' => true, 'expected_interval_seconds' => 60]);
        $sensor->asset_id = $asset->id;
        $sensor->measurement_type_id = $type->id;
        $sensor->save();

        return $sensor;
    }

    private function source(?Sensor $sensor = null, ?User $actor = null): array
    {
        return app(HttpSourceAdministration::class)->apply($actor ?? $this->admin(), ['sensor_id' => ($sensor ?? $this->sensor())->id, 'expires_days' => 90]);
    }

    private function payload(array $extra = []): array
    {
        return ['value' => '20.125000001', 'unit' => 'test', 'observed_at' => now()->subSecond()->toISOString(), ...$extra];
    }

    private function send(HttpMeasurementSource $source, string $token, array $extra = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/ingestion/http/'.$source->id, $this->payload($extra));
    }

    public function test_delete_requires_current_version_and_preserves_measurement_history(): void
    {
        [$source, $token] = $this->source();
        $path = '/api/v1/integrations/http/sources/'.$source->id;
        $this->actingAs(User::factory()->create())->deleteJson($path, ['version' => 1])->assertForbidden();
        $this->actingAs($this->admin())->deleteJson($path, ['version' => 2])->assertConflict();
        $this->send($source, $token)->assertCreated();
        $this->deleteJson($path, ['version' => 1])->assertConflict();
        $this->assertDatabaseCount('measurements', 1);

        [$source, $token] = $this->source();
        $path = '/api/v1/integrations/http/sources/'.$source->id;
        $this->deleteJson($path, ['version' => 1])->assertNoContent();
        $this->assertModelMissing($source);
    }

    public function test_guests_members_and_sensor_delegates_cannot_manage_http_sources(): void
    {
        $this->getJson('/api/v1/integrations/http/sources')->assertUnauthorized();
        $this->postJson('/api/v1/integrations/http/sources', [])->assertUnauthorized();
        foreach ([[], ['sensors.manage']] as $grants) {
            $member = User::factory()->create(['permission_grants' => $grants]);
            $this->actingAs($member)->getJson('/api/v1/integrations/http/sources')->assertForbidden();
            $this->postJson('/api/v1/integrations/http/sources', [])->assertForbidden();
        }
    }

    public function test_admin_and_integration_delegate_issue_scoped_hashed_expiring_credentials_once(): void
    {
        foreach ([$this->admin(), User::factory()->create(['permission_grants' => ['integrations.manage']])] as $actor) {
            $sensor = $this->sensor();
            $response = $this->actingAs($actor)->postJson('/api/v1/integrations/http/sources', ['sensor_id' => $sensor->id, 'expires_days' => 90])->assertCreated();
            $token = $response->json('data.token');
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
            $source = HttpMeasurementSource::findOrFail($response->json('data.source.id'));
            $this->assertSame(hash('sha256', $token), $source->token_hash);
            $this->assertNotSame($token, $source->token_hash);
            $this->assertSame($actor->id, $source->issuer_id);
            $this->assertSame(0, $source->issuer_version);
            $this->assertEquals(now()->addDays(90), $source->expires_at);
            $list = $this->getJson('/api/v1/integrations/http/sources')->assertOk();
            $this->assertStringContainsString('no-store', $list->headers->get('Cache-Control'));
            foreach ([$token, $source->token_hash, $actor->email] as $secret) {
                $this->assertStringNotContainsString($secret, $list->getContent());
            }
            $this->assertArrayNotHasKey('token_hash', $source->toArray());
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_manual_source_requires_existing_enabled_nonretired_unlinked_sensor(): void
    {
        $admin = $this->admin();
        $sensor = $this->sensor();
        $this->actingAs($admin)->postJson('/api/v1/integrations/http/sources', ['sensor_id' => '00000000-0000-4000-8000-000000000001', 'expires_days' => 90])->assertUnprocessable();
        $sensor->is_enabled = false;
        $sensor->save();
        $this->postJson('/api/v1/integrations/http/sources', ['sensor_id' => $sensor->id, 'expires_days' => 90])->assertUnprocessable();
        $sensor->is_enabled = true;
        $sensor->save();
        $sensor->device->update(['status' => 'retired']);
        $this->postJson('/api/v1/integrations/http/sources', ['sensor_id' => $sensor->id, 'expires_days' => 90])->assertUnprocessable();
        $sensor->device->update(['status' => 'active']);
        HomeAssistantLink::create(['sensor_id' => $sensor->id, 'instance_key' => hash('sha256', 'fictional-instance'), 'entity_id' => 'sensor.fictional_http']);
        $this->postJson('/api/v1/integrations/http/sources', ['sensor_id' => $sensor->id, 'expires_days' => 90])->assertStatus(409);
        $this->assertDatabaseCount('http_measurement_sources', 0);
    }

    public function test_a_sensor_gets_only_one_http_source_with_immutable_target(): void
    {
        $admin = $this->admin();
        $sensor = $this->sensor();
        [$source] = $this->source($sensor, $admin);
        $other = $this->sensor();
        $this->actingAs($admin)->postJson('/api/v1/integrations/http/sources', ['sensor_id' => $sensor->id, 'expires_days' => 90])->assertStatus(409);
        $this->patchJson('/api/v1/integrations/http/sources/'.$source->id, ['version' => 1, 'is_enabled' => false, 'sensor_id' => $other->id, 'issuer_id' => 999])->assertOk();
        $this->assertSame($sensor->id, $source->fresh()->sensor_id);
        $this->assertSame($admin->id, $source->fresh()->issuer_id);
    }

    public function test_management_validation_rejects_bad_lifetimes_ids_versions_and_flags(): void
    {
        $admin = $this->admin();
        $sensor = $this->sensor();
        foreach ([0, 366, 1.5, 'invalid'] as $days) {
            $this->actingAs($admin)->postJson('/api/v1/integrations/http/sources', ['sensor_id' => $sensor->id, 'expires_days' => $days])->assertUnprocessable();
        }
        [$source] = $this->source($sensor, $admin);
        $this->postJson('/api/v1/integrations/http/sources/'.$source->id.'/rotate', ['expires_days' => 30])->assertUnprocessable();
        $this->patchJson('/api/v1/integrations/http/sources/'.$source->id, ['version' => 1, 'is_enabled' => 'invalid'])->assertUnprocessable();
        $this->assertSame(1, $source->fresh()->version);
    }

    public function test_session_authentication_cannot_replace_the_scoped_bearer_token(): void
    {
        [$source, $token] = $this->source();
        $this->postJson('/api/v1/ingestion/http/'.$source->id, $this->payload())->assertUnauthorized();
        $this->actingAs($this->admin())->postJson('/api/v1/ingestion/http/'.$source->id, $this->payload())->assertUnauthorized();
        $this->send($source, str_repeat('a', 64))->assertUnauthorized()->assertJsonPath('error.code', 'httpIngestion.errors.invalidCredential');
        $this->send($source, 'malformed')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/ingestion/http/00000000-0000-4000-8000-000000000001', $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_out_of_range_known_percentage_is_preserved_as_suspect(): void
    {
        $sensor = $this->sensor('%');
        $sensor->measurementType->update(['nature' => 'substrate_moisture']);
        [$source, $token] = $this->source($sensor);
        $this->send($source, $token, ['value' => '101', 'unit' => '%', 'quality' => 'good'])->assertCreated();
        $this->assertSame('suspect', Measurement::sole()->quality);
        $this->assertSame('101.000000000', Measurement::sole()->value);
    }

    public function test_quality_metadata_is_immutable_and_invalid_quality_never_creates_a_sample(): void
    {
        [$source, $token] = $this->source();
        $this->send($source, $token, ['quality' => 'suspect'])->assertCreated();
        $this->assertSame('suspect', Measurement::sole()->quality);
        $this->send($source, $token, ['quality' => 'suspect'])->assertOk();
        $this->send($source, $token, ['quality' => 'good'])->assertConflict();
        $this->send($source, $token, ['quality' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors('quality');
        $this->send($source, $token, ['quality' => null])->assertUnprocessable();
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_ingestion_stores_exact_decimal_time_unit_provenance_and_current_location(): void
    {
        $sensor = $this->sensor();
        $site = Site::create(['name' => 'Fictional HTTP Site']);
        $this->placeAsset($sensor->device, ['site_id' => $site->id]);
        [$source, $token] = $this->source($sensor);
        $this->travel(2)->seconds();
        $response = $this->send($source, $token, ['value' => '-1.234567891e2'])->assertCreated()->assertExactJson(['data' => ['outcome' => 'stored']]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $reading = Measurement::firstOrFail();
        $this->assertSame('-123.456789100', $reading->value);
        $this->assertSame($source->id, $reading->http_measurement_source_id);
        $this->assertNull($reading->home_assistant_link_id);
        $this->assertSame($sensor->id, $reading->sensor_id);
        $this->assertSame('test', $reading->unit);
        $this->assertStringContainsString('Fictional HTTP Site', json_encode($reading->location_snapshot));
        $this->assertEquals(now()->subSecond(), $reading->observed_at);
        $this->assertEquals(now(), $source->fresh()->last_received_at);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_identical_timestamp_retries_are_deduplicated_and_conflicting_values_are_rejected(): void
    {
        [$source, $token] = $this->source();
        $this->send($source, $token)->assertCreated();
        $this->send($source, $token)->assertOk()->assertJsonPath('data.outcome', 'duplicate');
        $this->send($source, $token, ['value' => '99'])->assertStatus(409)->assertJsonPath('error.code', 'httpIngestion.errors.sampleConflict');
        $this->assertDatabaseCount('measurements', 1);
        $this->assertSame('20.125000001', Measurement::first()->value);
    }

    public function test_token_is_scoped_to_one_source_and_cannot_read_or_manage_records(): void
    {
        [$one, $token] = $this->source();
        [$two] = $this->source();
        $this->send($two, $token)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/integrations/http/sources')->assertUnauthorized();
        $this->postJson('/api/v1/integrations/http/sources', [])->assertUnauthorized();
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_invalid_precision_numeric_json_unit_and_timestamps_do_not_mutate_history(): void
    {
        [$source, $token] = $this->source();
        foreach ([['value' => 20.1], ['value' => '1234567890123'], ['value' => '0.1234567891'], ['value' => 'NaN'], ['unit' => 'other'], ['unit' => null], ['observed_at' => '2026-02-30T00:00:00Z'], ['observed_at' => '2026-11-07T12:00:00'], ['observed_at' => '1999-12-31T23:59:59Z'], ['observed_at' => now()->addMinutes(6)->toISOString()]] as $extra) {
            $this->send($source, $token, $extra)->assertUnprocessable();
        }
        $this->assertDatabaseCount('measurements', 0);
        $this->assertNull($source->fresh()->last_received_at);
    }

    public function test_unitless_readings_require_explicit_null_and_timezone_offsets_are_canonicalized(): void
    {
        [$source, $token] = $this->source($this->sensor(null));
        $time = now()->subMinute();
        $this->send($source, $token, ['unit' => null, 'observed_at' => $time->setTimezone('Europe/Paris')->toIso8601String()])->assertCreated();
        $this->assertEquals($time, Measurement::first()->observed_at);
        $this->assertNull(Measurement::first()->unit);
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/ingestion/http/'.$source->id, ['value' => '1', 'observed_at' => now()->toISOString()])->assertUnprocessable();
    }

    public function test_pause_resume_rotation_and_revocation_have_immediate_credential_effects(): void
    {
        $admin = $this->admin();
        [$source, $old] = $this->source(null, $admin);
        $url = '/api/v1/integrations/http/sources/'.$source->id;
        $this->actingAs($admin)->patchJson($url, ['version' => 1, 'is_enabled' => false])->assertOk()->assertJsonPath('data.token', null);
        $this->send($source, $old)->assertUnauthorized();
        $rotated = $this->postJson($url.'/rotate', ['version' => 2, 'expires_days' => 30])->assertOk()->assertJsonPath('data.source.isEnabled', false)->json('data.token');
        $this->assertNotSame($old, $rotated);
        $this->patchJson($url, ['version' => 3, 'is_enabled' => true])->assertOk();
        $this->send($source, $old)->assertUnauthorized();
        $this->send($source, $rotated)->assertCreated();
        $this->postJson($url.'/revoke', ['version' => 4])->assertOk()->assertJsonPath('data.source.isEnabled', false);
        $this->assertNull($source->fresh()->token_hash);
        $this->send($source, $rotated)->assertUnauthorized();
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_expiry_and_issuer_account_version_invalidate_credentials(): void
    {
        $admin = $this->admin();
        [$source, $token] = $this->source(null, $admin);
        $source->expires_at = now();
        $source->save();
        $this->send($source, $token)->assertUnauthorized();
        $source->expires_at = now()->addDay();
        $source->save();
        $admin->increment('session_version');
        $this->send($source, $token)->assertUnauthorized();
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_loss_of_integration_permission_or_account_activation_cannot_accept_samples(): void
    {
        $actor = User::factory()->create(['permission_grants' => ['integrations.manage']]);
        [$source, $token] = $this->source(null, $actor);
        $actor->permission_grants = [];
        $actor->save();
        $this->send($source, $token)->assertUnauthorized();
        $actor->permission_grants = ['integrations.manage'];
        $actor->is_active = false;
        $actor->save();
        $this->send($source, $token)->assertUnauthorized();
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_disabled_sensors_and_retired_devices_stop_ingestion_but_preserve_old_readings(): void
    {
        [$source, $token] = $this->source();
        $this->send($source, $token)->assertCreated();
        $source->sensor->update(['is_enabled' => false]);
        $this->send($source, $token, ['observed_at' => now()->toISOString()])->assertStatus(409);
        $source->sensor->update(['is_enabled' => true]);
        $source->sensor->device->update(['status' => 'retired']);
        $this->send($source, $token)->assertStatus(409);
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_stale_source_edits_conflict_and_noop_toggles_preserve_version(): void
    {
        $admin = $this->admin();
        [$source] = $this->source(null, $admin);
        $url = '/api/v1/integrations/http/sources/'.$source->id;
        $this->actingAs($admin)->patchJson($url, ['version' => 1, 'is_enabled' => true])->assertOk()->assertJsonPath('data.source.version', 1);
        $this->patchJson($url, ['version' => 1, 'is_enabled' => false])->assertOk()->assertJsonPath('data.source.version', 2);
        $this->postJson($url.'/rotate', ['version' => 1, 'expires_days' => 90])->assertStatus(409);
        $this->assertSame(2, $source->fresh()->version);
    }

    public function test_json_and_four_kib_payload_bound_are_enforced(): void
    {
        [$source, $token] = $this->source();
        $this->withHeader('Authorization', 'Bearer '.$token)->post('/api/v1/ingestion/http/'.$source->id, $this->payload())->assertStatus(415);
        $this->send($source, $token, ['padding' => str_repeat('x', 4096)])->assertStatus(413);
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_valid_source_rate_limit_counts_duplicate_requests_without_creating_more_readings(): void
    {
        [$source, $token] = $this->source();
        $this->send($source, $token)->assertCreated();
        for ($i = 0; $i < 59; $i++) {
            $this->send($source, $token)->assertOk();
        }
        $this->send($source, $token)->assertStatus(429);
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_http_readings_are_visible_to_members_in_history_and_reports(): void
    {
        [$source, $token] = $this->source();
        $this->send($source, $token)->assertCreated();
        $member = User::factory()->create();
        $this->actingAs($member)->getJson('/api/v1/sensors/'.$source->sensor_id.'/measurements')->assertOk()->assertJsonPath('data.0.value', '20.125000001');
        $report = $this->getJson('/api/v1/reports/sensors/'.$source->sensor_id)->assertOk();
        $this->assertStringContainsString('20.125000001', $report->getContent());
    }

    public function test_http_monitoring_uses_readings_and_explicit_pause_suppresses_without_fabricated_recovery(): void
    {
        [$source, $token] = $this->source();
        $this->travel(2)->seconds();
        $this->send($source, $token)->assertCreated();
        $rule = AlertRule::create(['sensor_id' => $source->sensor_id, 'name' => 'Fictional HTTP Rule', 'kind' => 'threshold', 'severity' => 'warning', 'maximum' => '10', 'max_age_seconds' => 600, 'activated_at' => now()->subHour()]);
        app(AlertEvaluator::class)->evaluate($rule);
        $this->assertSame('alerting', $rule->fresh()->last_result);
        $source->is_enabled = false;
        $source->save();
        app(AlertEvaluator::class)->evaluate($rule);
        $this->assertSame('suppressed', $rule->fresh()->last_result);
        $this->assertSame('source_disabled', $rule->episodes()->sole()->resolution);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_sanitized_audit_does_not_include_tokens_values_times_or_account_addresses(): void
    {
        [$source, $token] = $this->source();
        Log::spy();
        $this->send($source, $token)->assertCreated();
        Log::shouldHaveReceived('info')->with('http_ingestion.accepted', ['source_uuid' => $source->id, 'sensor_uuid' => $source->sensor_id, 'outcome' => 'stored'])->once();
    }

    public function test_schema_reapplication_preserves_existing_home_assistant_readings_and_credentials(): void
    {
        $sensor = $this->sensor();
        $link = HomeAssistantLink::create(['sensor_id' => $sensor->id, 'instance_key' => hash('sha256', 'fictional'), 'entity_id' => 'sensor.schema_fixture']);
        Measurement::create(['sensor_id' => $sensor->id, 'home_assistant_link_id' => $link->id, 'value' => '5.123456789', 'unit' => 'test', 'observed_at' => now(), 'received_at' => now()]);
        $admin = $this->admin();
        $hash = $admin->password;
        $this->reapplySchemaFrom('2026_11_07_050000_create_http_measurement_sources.php');
        $this->assertSame('5.123456789', Measurement::sole()->value);
        $this->assertSame($link->id, Measurement::sole()->home_assistant_link_id);
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertDatabaseCount('http_measurement_sources', 0);
    }
}
