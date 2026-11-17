<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\HomeAssistantLink;
use App\Models\HttpMeasurementSource;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\PermissionRole;
use App\Models\Sensor;
use App\Models\User;
use App\Services\HttpSourceAdministration;
use App\Services\SourceDiagnostics;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SourceDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-07T12:00:00.987654Z'));
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function sensor(string $name = 'Fictional Diagnostic Probe', ?int $interval = 60): Sensor
    {
        $asset = Asset::create(['name' => 'Fictional Diagnostic Device', 'status' => 'active']);
        $type = MeasurementType::firstOrCreate(['code' => 'diagnostic_type'], ['name' => 'Fictional Diagnostic Type', 'unit' => 'test']);
        $sensor = new Sensor(['name' => $name, 'channel_key' => 'probe', 'expected_interval_seconds' => $interval]);
        $sensor->asset_id = $asset->id;
        $sensor->measurement_type_id = $type->id;
        $sensor->save();

        return $sensor;
    }

    private function ha(Sensor $sensor, array $extra = []): HomeAssistantLink
    {
        return HomeAssistantLink::create(['sensor_id' => $sensor->id, 'instance_key' => hash('sha256', 'fictional-private-instance'), 'entity_id' => 'sensor.private_'.str_replace('-', '', $sensor->id), ...$extra]);
    }

    private function http(User $issuer, Sensor $sensor): HttpMeasurementSource
    {
        return app(HttpSourceAdministration::class)->apply($issuer, ['sensor_id' => $sensor->id, 'expires_days' => 90])[0];
    }

    private function reading(Sensor $sensor, HomeAssistantLink|HttpMeasurementSource $source, CarbonImmutable $time, ?CarbonImmutable $received = null): void
    {
        Measurement::create(['sensor_id' => $sensor->id, 'home_assistant_link_id' => $source instanceof HomeAssistantLink ? $source->id : null, 'http_measurement_source_id' => $source instanceof HttpMeasurementSource ? $source->id : null, 'value' => '98765.123456789', 'unit' => 'test', 'observed_at' => $time, 'received_at' => $received ?? now(), 'location_snapshot' => ['private_fixture_location' => 'Not part of diagnostics']]);
    }

    private function read(User $actor, array $criteria = []): array
    {
        return app(SourceDiagnostics::class)->read($actor, $criteria);
    }

    public function test_guests_members_and_nonintegration_delegates_cannot_read_source_diagnostics(): void
    {
        $this->getJson('/api/v1/integrations/sources')->assertUnauthorized();
        foreach ([[], ['sensors.manage'], ['alerts.manage']] as $grants) {
            $this->actingAs(User::factory()->create(['permission_grants' => $grants]))->getJson('/api/v1/integrations/sources')->assertForbidden();
        }
    }

    public function test_admin_and_direct_or_profile_integration_delegates_get_empty_private_summaries(): void
    {
        $profile = PermissionRole::create(['name' => 'Diagnostic Integration Profile', 'permissions' => ['integrations.manage']]);
        foreach ([$this->admin(), User::factory()->create(['permission_grants' => ['integrations.manage']]), User::factory()->create(['permission_role_ids' => [$profile->id]])] as $actor) {
            $response = $this->actingAs($actor)->getJson('/api/v1/integrations/sources')->assertOk()->assertJsonPath('summary.total', 0)->assertJsonPath('summary.states.enabled', 0)->assertJsonPath('summary.readings.missing', 0)->assertJsonPath('asOf', '2026-11-07T12:00:00.000000Z');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $response->assertJsonCount(0, 'data');
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_mixed_sources_separate_configuration_state_from_reading_age_and_do_not_count_unlinked_sensors(): void
    {
        $admin = $this->admin();
        $this->sensor('Unlinked Manual Probe');
        $haSensor = $this->sensor('A HA Fresh');
        $ha = $this->ha($haSensor, ['last_error' => 'measurements.errors.providerUnavailable']);
        $this->reading($haSensor, $ha, CarbonImmutable::now()->subMinute());
        $httpSensor = $this->sensor('B HTTP Old');
        $http = $this->http($admin, $httpSensor);
        $this->reading($httpSensor, $http, CarbonImmutable::now()->subHour());
        $data = $this->read($admin);
        $this->assertSame(2, $data['summary']['total']);
        $this->assertSame(1, $data['summary']['homeAssistant']);
        $this->assertSame(1, $data['summary']['http']);
        $this->assertSame(2, $data['summary']['states']['enabled']);
        $this->assertSame(['fresh' => 1, 'stale' => 1, 'missing' => 0, 'unknown' => 0], $data['summary']['readings']);
        $this->assertSame('enabled', $data['data'][0]['state']);
        $this->assertSame('fresh', $data['data'][0]['freshness']);
        $this->assertSame('measurements.errors.providerUnavailable', $data['data'][0]['lastError']);
        $this->assertNull($data['data'][0]['credentialCurrent']);
        $this->assertTrue($data['data'][1]['credentialCurrent']);
    }

    public function test_summary_configuration_buckets_have_explicit_precedence_and_ready_reading_counts_exclude_blocked_sources(): void
    {
        $admin = $this->admin();
        $paused = $this->sensor('Paused disabled');
        $paused->is_enabled = false;
        $paused->save();
        $this->ha($paused, ['is_enabled' => false]);
        $disabled = $this->sensor('Disabled');
        $disabled->is_enabled = false;
        $disabled->save();
        $this->ha($disabled);
        $retired = $this->sensor('Retired');
        $retired->device->update(['status' => 'retired']);
        $this->ha($retired);
        $invalid = $this->http($admin, $this->sensor('Invalid credential'));
        $invalid->token_hash = null;
        $invalid->save();
        $this->ha($this->sensor('Enabled missing'));
        $data = $this->read($admin);
        $this->assertSame(['enabled' => 1, 'paused' => 1, 'sensor_disabled' => 1, 'device_retired' => 1, 'credential_invalid' => 1, 'source_unavailable' => 0], $data['summary']['states']);
        $this->assertSame(['fresh' => 0, 'stale' => 0, 'missing' => 1, 'unknown' => 0], $data['summary']['readings']);
        $this->assertSame(5, array_sum($data['summary']['states']));
    }

    public function test_missing_readings_take_precedence_over_unknown_interval_and_existing_readings_without_interval_are_unqualified(): void
    {
        $admin = $this->admin();
        $empty = $this->sensor('A No interval no reading', null);
        $this->ha($empty);
        $read = $this->sensor('B No interval with reading', null);
        $link = $this->ha($read);
        $this->reading($read, $link, CarbonImmutable::now()->subYears(2));
        $data = $this->read($admin);
        $this->assertSame('missing', $data['data'][0]['freshness']);
        $this->assertSame('unknown', $data['data'][1]['freshness']);
        $this->assertSame(1, $data['summary']['readings']['missing']);
        $this->assertSame(1, $data['summary']['readings']['unknown']);
    }

    public function test_freshness_uses_twice_expected_interval_or_120_seconds_with_exact_utc_second_cutoff(): void
    {
        $admin = $this->admin();
        $asOf = CarbonImmutable::now()->startOfSecond();
        foreach ([['A boundary', 10, $asOf->subSeconds(120), 'fresh'], ['B microsecond old', 10, $asOf->subSeconds(120)->subMicrosecond(), 'stale'], ['C long interval', 600, $asOf->subSeconds(1199), 'fresh']] as [$name, $interval, $time, $expected]) {
            $sensor = $this->sensor($name, $interval);
            $link = $this->ha($sensor);
            $this->reading($sensor, $link, $time);
            $data = $this->read($admin, ['search' => $name]);
            $this->assertSame($expected, $data['data'][0]['freshness']);
        }
    }

    public function test_http_credential_diagnostics_match_issuer_activation_version_permissions_profiles_expiry_and_revocation(): void
    {
        $admin = $this->admin();
        $profile = PermissionRole::create(['name' => 'HTTP diagnostics profile', 'permissions' => ['integrations.manage']]);
        $direct = User::factory()->create(['permission_grants' => ['integrations.manage']]);
        $viaProfile = User::factory()->create(['permission_role_ids' => [$profile->id]]);
        $one = $this->http($direct, $this->sensor('A direct'));
        $two = $this->http($viaProfile, $this->sensor('B profile'));
        $three = $this->http($admin, $this->sensor('C admin'));
        $this->assertSame(3, $this->read($admin)['summary']['states']['enabled']);
        $direct->permission_grants = [];
        $direct->save();
        $profile->permissions = [];
        $profile->save();
        $three->expires_at = now()->startOfSecond();
        $three->save();
        $this->assertSame(3, $this->read($admin)['summary']['states']['credential_invalid']);
        $direct->permission_grants = ['integrations.manage'];
        $direct->is_active = false;
        $direct->save();
        $one->issuer_version = 999;
        $one->save();
        $data = $this->read($admin);
        $this->assertFalse($data['data'][0]['credentialCurrent']);
        $direct->is_active = true;
        $direct->save();
        $one->issuer_version = $direct->session_version;
        $one->save();
        $this->assertTrue($this->read($admin)['data'][0]['credentialCurrent']);
        $one->token_hash = null;
        $one->save();
        $this->assertFalse($this->read($admin)['data'][0]['credentialCurrent']);
    }

    public function test_adapter_activity_and_latest_observation_receipt_do_not_mix_backfilled_or_duplicate_times(): void
    {
        $admin = $this->admin();
        $sensor = $this->sensor();
        $source = $this->http($admin, $sensor);
        $latest = CarbonImmutable::now()->subHour();
        $received = CarbonImmutable::now()->subMinutes(30);
        $this->reading($sensor, $source, $latest, $received);
        $this->reading($sensor, $source, $latest->subDay(), CarbonImmutable::now());
        $source->last_received_at = now();
        $source->save();
        $row = $this->read($admin)['data'][0];
        $this->assertSame($latest->toISOString(), $row['lastObservedAt']);
        $this->assertSame($received->toISOString(), $row['latestReceivedAt']);
        $this->assertSame($source->fresh()->last_received_at->toISOString(), $row['lastAcceptedAt']);
        $this->assertSame('stale', $row['freshness']);
    }

    public function test_filters_and_literal_search_do_not_change_the_installation_wide_summary(): void
    {
        $admin = $this->admin();
        $one = $this->sensor('Percent % Probe');
        $this->ha($one, ['is_enabled' => false]);
        $this->http($admin, $this->sensor('Ordinary HTTP Probe'));
        $data = $this->read($admin, ['kind' => 'home_assistant', 'state' => 'paused', 'freshness' => 'missing', 'search' => '%']);
        $this->assertCount(1, $data['data']);
        $this->assertSame(2, $data['summary']['total']);
        $this->assertSame(1, $data['meta']['total']);
        $this->assertCount(0, $this->read($admin, ['kind' => 'http', 'search' => '%'])['data']);
    }

    public function test_pagination_is_stable_by_sensor_name_kind_and_uuid(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 23; $i++) {
            $this->ha($this->sensor(sprintf('Probe %02d', $i)));
        }
        $one = $this->read($admin);
        $two = $this->read($admin, ['page' => 2]);
        $this->assertCount(20, $one['data']);
        $this->assertCount(3, $two['data']);
        $this->assertSame('Probe 20', $two['data'][0]['sensorName']);
        $this->assertSame(23, $two['summary']['total']);
    }

    public function test_safe_diagnostics_do_not_return_private_instance_entity_credentials_identities_values_or_locations(): void
    {
        $admin = $this->admin();
        $sensor = $this->sensor();
        $link = $this->ha($sensor, ['last_error' => 'fictional-private-error-payload']);
        $this->reading($sensor, $link, CarbonImmutable::now());
        $source = $this->http($admin, $this->sensor('HTTP private probe'));
        $response = $this->actingAs($admin)->getJson('/api/v1/integrations/sources')->assertOk();
        foreach ([$admin->email, $admin->uuid, $admin->password, $link->instance_key, $link->entity_id, $link->last_error, $source->token_hash, '98765.123456789', 'private_fixture_location'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertNull($response->json('data.0.lastError'));
        $this->assertTrue($response->json('data.0.hasUnknownError'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_invalid_filters_are_rejected_before_registry_queries(): void
    {
        $admin = $this->admin();
        foreach (['kind=invalid', 'state=healthy', 'freshness=online', 'page=0', 'page=1000001', 'search='.str_repeat('x', 121)] as $query) {
            $this->actingAs($admin)->getJson('/api/v1/integrations/sources?'.$query)->assertUnprocessable();
        }
    }

    public function test_inactive_stale_or_permission_revoked_actors_cannot_read_diagnostics(): void
    {
        $admin = $this->admin();
        $admin->is_active = false;
        $admin->save();
        $this->actingAs($admin)->getJson('/api/v1/integrations/sources')->assertUnauthorized();
        $active = $this->admin();
        $stale = clone $active;
        $active->increment('session_version');
        try {
            $this->read($stale);
            $this->fail('Stale actor accepted.');
        } catch (AuthenticationException) {
        }
        $current = $this->admin();
        $old = clone $current;
        $current->permission_role_ids = [AccessProfileSeeder::defaultProfile()->id];
        $current->save();
        try {
            $this->read($old);
            $this->fail('Revoked integration permission accepted.');
        } catch (AuthorizationException) {
        }
    }

    public function test_source_diagnostic_rate_limit_is_per_account_and_reads_do_not_modify_source_state(): void
    {
        $admin = $this->admin();
        $sensor = $this->sensor();
        $source = $this->http($admin, $sensor);
        $before = $source->fresh()->getAttributes();
        $this->actingAs($admin);
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/integrations/sources')->assertOk();
        } $this->getJson('/api/v1/integrations/sources')->assertStatus(429);
        $this->assertSame($before, $source->fresh()->getAttributes());
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_query_count_is_bounded_independently_of_the_number_of_sources(): void
    {
        $admin = $this->admin();
        $this->ha($this->sensor('First Probe'));
        $admin->load('permissionRoles');
        DB::enableQueryLog();
        $this->read($admin);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        for ($i = 0; $i < 25; $i++) {
            $this->ha($this->sensor('Extra Probe '.$i));
        } DB::flushQueryLog();
        $this->read($admin);
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(8, $many);
    }

    public function test_sensor_detail_endpoint_preserves_member_read_policy_and_existing_configuration_shape(): void
    {
        $sensor = $this->sensor();
        $path = '/api/v1/sensors/'.$sensor->id;
        $this->getJson($path)->assertUnauthorized();
        $member = User::factory()->create();
        $this->actingAs($member)->getJson($path)->assertOk()->assertJsonPath('data.id', $sensor->id)->assertJsonPath('data.device.name', 'Fictional Diagnostic Device');
        $this->getJson('/api/v1/sensors/00000000-0000-4000-8000-000000000001')->assertNotFound();
    }
}
