<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\MqttMeasurementSource;
use App\Models\Sensor;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\HttpSourceAdministration;
use App\Services\MqttConfiguration;
use App\Services\MqttMeasurementIngestion;
use App\Services\MqttSourceAdministration;
use App\Services\MqttSubscriber;
use App\Services\SourceDiagnostics;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MqttIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mqtt.enabled' => true, 'mqtt.host' => 'fictional-broker', 'mqtt.port' => 1883, 'mqtt.tls' => false]);
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo(now()->startOfSecond());
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function sensor(): Sensor
    {
        $asset = Asset::create(['name' => 'Fictional MQTT Device', 'status' => 'active']);
        $type = MeasurementType::create(['name' => 'Fictional MQTT Type', 'code' => 'mqtt_'.bin2hex(random_bytes(5)), 'unit' => 'test']);
        $sensor = new Sensor(['name' => 'Fictional MQTT Probe', 'channel_key' => 'probe', 'expected_interval_seconds' => 60]);
        $sensor->asset_id = $asset->id;
        $sensor->measurement_type_id = $type->id;
        $sensor->save();

        return $sensor;
    }

    private function source(?Sensor $sensor = null): MqttMeasurementSource
    {
        return app(MqttSourceAdministration::class)->save($this->admin(), ['sensor_id' => ($sensor ?? $this->sensor())->id, 'topic' => 'fictional/'.bin2hex(random_bytes(6))]);
    }

    private function ingest(MqttMeasurementSource $source, array $extra = []): string
    {
        return app(MqttMeasurementIngestion::class)->ingest($source->id, $source->version, $source->topic, json_encode(['value' => '41.123456789', 'unit' => 'test', 'observed_at' => now()->subSecond()->toISOString(), ...$extra]));
    }

    public function test_delete_requires_current_version_and_preserves_measurement_history(): void
    {
        $source = $this->source();
        $path = '/api/v1/integrations/mqtt/sources/'.$source->id;
        $this->actingAs(User::factory()->create())->deleteJson($path, ['version' => 1])->assertForbidden();
        $this->actingAs($this->admin())->deleteJson($path, ['version' => 2])->assertConflict();
        $this->ingest($source);
        $this->deleteJson($path, ['version' => 1])->assertConflict();
        $this->assertDatabaseCount('measurements', 1);

        $source = $this->source();
        $path = '/api/v1/integrations/mqtt/sources/'.$source->id;
        $this->deleteJson($path, ['version' => 1])->assertNoContent();
        $this->assertModelMissing($source);
    }

    public function test_management_requires_integration_permission_and_is_private(): void
    {
        $this->getJson('/api/v1/integrations/mqtt')->assertUnauthorized();
        foreach ([[], ['sensors.manage']] as $permissions) {
            $this->actingAs(User::factory()->create(['permission_grants' => $permissions]))->getJson('/api/v1/integrations/mqtt/sources')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['permission_grants' => ['integrations.manage']]))->getJson('/api/v1/integrations/mqtt')->assertOk()->assertJsonPath('data.configured', true)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_management_creates_safe_metadata_and_versioned_pause_without_reading_provider(): void
    {
        $sensor = $this->sensor();
        $this->actingAs($this->admin());
        $source = $this->postJson('/api/v1/integrations/mqtt/sources', ['sensor_id' => $sensor->id, 'topic' => 'fictional/probe'])->assertCreated()->assertJsonPath('data.topic', 'fictional/probe')->assertJsonMissingPath('data.instance_key')->json('data.id');
        $this->patchJson('/api/v1/integrations/mqtt/sources/'.$source, ['version' => 1, 'is_enabled' => false])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/integrations/mqtt/sources/'.$source, ['version' => 1, 'is_enabled' => true])->assertConflict();
        $this->assertDatabaseCount('measurements', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_wildcards_empty_system_control_and_oversized_topics_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $sensor = $this->sensor();
        foreach (['#', 'fixture/+', '$SYS/test', "test\nprobe", 'test space', '', str_repeat('x', 201)] as $topic) {
            $this->postJson('/api/v1/integrations/mqtt/sources', ['sensor_id' => $sensor->id, 'topic' => $topic])->assertUnprocessable();
        }
    }

    public function test_sensor_and_topic_are_unique_and_http_cannot_attach_to_mqtt_sensor(): void
    {
        $source = $this->source();
        $this->actingAs($this->admin());
        $this->postJson('/api/v1/integrations/mqtt/sources', ['sensor_id' => $source->sensor_id, 'topic' => 'other'])->assertConflict();
        $this->postJson('/api/v1/integrations/mqtt/sources', ['sensor_id' => $this->sensor()->id, 'topic' => $source->topic])->assertConflict();
        $this->postJson('/api/v1/integrations/http/sources', ['sensor_id' => $source->sensor_id, 'expires_days' => 90])->assertConflict();
        $http = app(HttpSourceAdministration::class)->apply($this->admin(), ['sensor_id' => $this->sensor()->id, 'expires_days' => 90])[0];
        $this->postJson('/api/v1/integrations/mqtt/sources', ['sensor_id' => $http->sensor_id, 'topic' => 'other'])->assertConflict();
    }

    public function test_disabled_configuration_prevents_source_creation_and_network_access(): void
    {
        config(['mqtt.enabled' => false]);
        $this->actingAs($this->admin())->postJson('/api/v1/integrations/mqtt/sources', ['sensor_id' => $this->sensor()->id, 'topic' => 'fictional/probe'])->assertUnprocessable();
        $this->artisan('measurements:mqtt --seconds=1')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_quality_is_checked_and_included_in_sample_identity(): void
    {
        $source = $this->source();
        $this->assertSame('stored', $this->ingest($source, ['quality' => 'invalid']));
        $this->assertSame('invalid', Measurement::sole()->quality);
        $this->assertSame('duplicate', $this->ingest($source, ['quality' => 'invalid']));
        $this->assertSame('conflict', $this->ingest($source, ['quality' => 'good']));
        $this->assertSame('rejected', $this->ingest($source, ['quality' => 'bad']));
        $this->assertSame('rejected', $this->ingest($source, ['quality' => null]));
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_exact_samples_are_immutable_idempotent_and_retain_provenance(): void
    {
        $source = $this->source();
        $this->assertSame('stored', $this->ingest($source));
        $this->assertSame('duplicate', $this->ingest($source, ['value' => '4.1123456789e1']));
        $this->assertSame('conflict', $this->ingest($source, ['value' => '42']));
        $reading = Measurement::sole();
        $this->assertSame('41.123456789', $reading->value);
        $this->assertSame($source->id, $reading->mqtt_measurement_source_id);
        $this->assertNull($reading->home_assistant_link_id);
        $this->assertNull($reading->http_measurement_source_id);
        $this->assertSame('mqtt.errors.sampleConflict', $source->fresh()->last_error);
        $this->assertSame('duplicate', $this->ingest($source));
        $this->assertNull($source->fresh()->last_error);
    }

    public function test_invalid_values_units_dates_and_missing_fields_never_create_measurements(): void
    {
        $source = $this->source();
        foreach ([['value' => 12], ['value' => 'NaN'], ['value' => '1.0000000001'], ['unit' => 'wrong'], ['observed_at' => now()->addMinutes(6)->toISOString()], ['observed_at' => '2026-02-30T12:00:00Z'], ['observed_at' => '2026-11-07T12:00:00'], ['observed_at' => '1999-12-31T23:59:59Z']] as $extra) {
            $this->assertSame('rejected', $this->ingest($source, $extra));
        }
        foreach (['{}', '[]', '{broken', str_repeat('x', 4097), '{"value":123456789012345678901234567890,"unit":"test","observed_at":"2026-11-07T12:00:00Z"}'] as $payload) {
            $this->assertSame('rejected', app(MqttMeasurementIngestion::class)->ingest($source->id, 1, $source->topic, $payload));
        }
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_old_retained_samples_do_not_become_fresh_when_received_now(): void
    {
        $source = $this->source();
        $time = now()->subDay()->toISOString();
        $this->assertSame('stored', $this->ingest($source, ['observed_at' => $time]));
        $this->assertSame('duplicate', $this->ingest($source, ['observed_at' => $time]));
        $diagnostics = app(SourceDiagnostics::class)->read($this->admin(), ['kind' => 'mqtt']);
        $this->assertSame(1, $diagnostics['summary']['mqtt']);
        $this->assertSame('stale', $diagnostics['data'][0]['freshness']);
        $this->assertStringNotContainsString($source->topic, json_encode($diagnostics));
    }

    public function test_paused_stale_version_disabled_sensor_retired_device_or_changed_broker_are_ignored(): void
    {
        $source = $this->source();
        $this->assertSame('ignored', app(MqttMeasurementIngestion::class)->ingest($source->id, 2, $source->topic, '{}'));
        $this->assertSame('ignored', app(MqttMeasurementIngestion::class)->ingest($source->id, 1, 'other', '{}'));
        $source->update(['is_enabled' => false]);
        $this->assertSame('ignored', $this->ingest($source));
        $source->update(['is_enabled' => true]);
        $source->sensor->update(['is_enabled' => false]);
        $this->assertSame('ignored', $this->ingest($source));
        $source->sensor->update(['is_enabled' => true]);
        $source->sensor->device->update(['status' => 'retired']);
        $this->assertSame('ignored', $this->ingest($source));
        $source->sensor->device->update(['status' => 'active']);
        config(['mqtt.host' => 'other-fictional-broker']);
        $this->assertSame('ignored', $this->ingest($source));
        $diagnostics = app(SourceDiagnostics::class)->read($this->admin(), ['kind' => 'mqtt']);
        $this->assertSame('source_unavailable', $diagnostics['data'][0]['state']);
        $this->assertNull($diagnostics['data'][0]['credentialCurrent']);
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_history_reports_and_alert_evaluation_consume_mqtt_readings(): void
    {
        $source = $this->source();
        $this->travel(2)->seconds();
        $this->ingest($source);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/sensors/'.$source->sensor_id.'/measurements')->assertOk()->assertJsonPath('data.0.value', '41.123456789');
        $rule = AlertRule::create(['sensor_id' => $source->sensor_id, 'name' => 'Fictional MQTT Rule', 'kind' => 'threshold', 'severity' => 'warning', 'minimum' => '50', 'max_age_seconds' => 120, 'is_enabled' => true, 'activated_at' => now()]);
        $this->assertSame('alerting', app(AlertEvaluator::class)->evaluate($rule)->last_result);
        $source->update(['is_enabled' => false]);
        $this->assertSame('suppressed', app(AlertEvaluator::class)->evaluate($rule)->last_result);
    }

    public function test_broker_configuration_is_validated_and_secret_free(): void
    {
        foreach (['https://fictional', 'user:secret@fictional', "fictional\n", ''] as $host) {
            config(['mqtt.host' => $host]);
            $this->assertFalse((bool) app(MqttConfiguration::class)->ready());
        }
    }

    public function test_real_transport_against_explicit_isolated_broker(): void
    {
        if (getenv('TEST_MQTT_HOST') !== 'canabicultarr-mqtt-test') {
            $this->markTestSkipped('Explicit isolated Mosquitto broker required.');
        }
        config(['mqtt.host' => 'canabicultarr-mqtt-test', 'mqtt.username' => null, 'mqtt.password' => null, 'mqtt.ca_file' => null]);
        $source = $this->source();
        $payload = json_encode(['value' => '41.123456789', 'unit' => 'test', 'observed_at' => now()->subSecond()->toISOString()]);
        $code = 'require "vendor/autoload.php";usleep(800000);$mqtt=new \PhpMqtt\Client\MqttClient("canabicultarr-mqtt-test",1883,"fictional-publisher-".bin2hex(random_bytes(4)),\PhpMqtt\Client\MqttClient::MQTT_3_1_1);$mqtt->connect();$mqtt->publish('.var_export($source->topic, true).','.var_export($payload, true).',1,true);$mqtt->loop(true,true,2);$mqtt->disconnect();';
        $process = proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
        try {
            fclose($pipes[0]);
            $this->assertSame(1, app(MqttSubscriber::class)->receive(2));
            $this->assertSame('41.123456789', Measurement::sole()->value);
            $this->assertSame(1, app(MqttSubscriber::class)->receive(1));
            $this->assertDatabaseCount('measurements', 1);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
        }
        $this->assertSame(0, $status);
    }
}
