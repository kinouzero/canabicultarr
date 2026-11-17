<?php

namespace Tests\Feature;

use App\Exceptions\MeasurementException;
use App\Models\Asset;
use App\Models\BluetoothDevice;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\User;
use App\Services\BluetoothDevices;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BluetoothDevicesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const ROOT = '/api/v1/integrations/bluetooth/devices';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function pair(): array
    {
        return app(BluetoothDevices::class)->register($this->admin, ['address' => 'AA:BB:CC:DD:EE:01', 'name' => 'Fictional BLE probe', 'kinds' => ['temperature', 'air_humidity', 'battery']]);
    }

    private function store(array $device, array $data): array
    {
        return app(BluetoothDevices::class)->ingest($this->admin, BluetoothDevice::findOrFail($device['id']), $data);
    }

    private function rejected(callable $work, int $status = 409): void
    {
        try {
            $work();
            $this->fail('Expected a rejected Bluetooth operation.');
        } catch (MeasurementException $error) {
            $this->assertSame($status, $error->status);
        }
    }

    private function reading(array $device, array $values = ['temperature' => '21.5']): array
    {
        $channels = collect($device['channels'])->keyBy('kind');

        return ['version' => $device['version'], 'observed_at' => now()->toISOString(), 'readings' => collect($values)->map(fn ($value, $kind) => ['kind' => $kind, 'version' => $channels[$kind]['version'], 'value' => $value])->values()->all()];
    }

    public function test_native_corebluetooth_uuid_identity_is_encrypted_and_reused(): void
    {
        $this->actingAs($this->admin);
        $input = ['address' => '12345678-1234-1234-1234-123456789ABC', 'name' => 'Native Mac sensor', 'kinds' => ['battery']];
        $first = app(BluetoothDevices::class)->register($this->admin, $input);
        $second = app(BluetoothDevices::class)->register($this->admin, $input);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseMissing('bluetooth_devices', ['address' => $input['address']]);
    }

    public function test_pairing_requires_a_global_integration_manager_and_never_promotes_an_account(): void
    {
        $this->getJson(self::ROOT)->assertUnauthorized();
        $reader = User::factory()->create();
        $this->actingAs($reader)->postJson(self::ROOT, ['browser_id' => 'x', 'name' => 'x', 'kinds' => ['battery']])->assertForbidden();
        $restricted = User::factory()->create(['permission_grants' => ['integrations.manage'], 'location_access' => []]);
        $this->actingAs($restricted)->postJson(self::ROOT, ['browser_id' => 'x', 'name' => 'x', 'kinds' => ['battery']])->assertForbidden();
        $this->assertDatabaseCount('bluetooth_devices', 0);
        $this->assertFalse($reader->fresh()->isAdministrator());
    }

    public function test_repeated_pairing_reuses_hardware_and_channels_without_resetting_user_changes(): void
    {
        $this->actingAs($this->admin);
        $first = $this->pair();
        $asset = Asset::findOrFail($first['assetId']);
        $asset->update(['name' => 'Renamed BLE probe']);
        Sensor::whereKey($first['channels'][0]['sensorId'])->update(['is_enabled' => false]);
        $again = $this->pair();
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame('Renamed BLE probe', $again['name']);
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('sensors', 3);
        $this->assertDatabaseMissing('bluetooth_devices', ['device_key' => 'AA:BB:CC:DD:EE:01']);
        $this->assertDatabaseMissing('bluetooth_devices', ['address' => 'AA:BB:CC:DD:EE:01']);
        $response = $this->getJson(self::ROOT)->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('AA:BB:CC:DD:EE:01', $response->getContent());
        $this->assertStringNotContainsString('device_key', $response->getContent());
    }

    public function test_standard_samples_are_idempotent_and_keep_battery_and_air_humidity_distinct(): void
    {
        $this->actingAs($this->admin);
        $device = $this->pair();
        $data = $this->reading($device, ['temperature' => '-3.25', 'air_humidity' => '35.75', 'battery' => '88']);
        $this->assertSame(3, $this->store($device, $data)['stored']);
        $this->assertSame(3, $this->store($device, $data)['duplicates']);
        $this->assertDatabaseCount('measurements', 3);
        $this->assertEqualsCanonicalizing(['temperature', 'air_humidity', 'battery'], Sensor::with('measurementType')->get()->pluck('measurementType.nature')->all());
        foreach (Measurement::all() as $sample) {
            $this->assertSame('bluetooth', $sample->provenance_snapshot['source']['kind']);
            $this->assertNotNull($sample->bluetooth_link_id);
            $this->assertSame('unknown', $sample->quality);
        }
    }

    public function test_a_conflicting_sample_rolls_back_every_new_reading_in_the_batch(): void
    {
        $this->actingAs($this->admin);
        $device = $this->pair();
        $this->store($device, $this->reading($device));
        $this->rejected(fn () => $this->store($device, $this->reading($device, ['air_humidity' => '50', 'temperature' => '22'])));
        $this->assertDatabaseCount('measurements', 1);
        $this->assertSame('21.500000000', Measurement::sole()->value);
    }

    public function test_disabling_channels_and_unpairing_reject_stale_work_but_preserve_history(): void
    {
        $this->actingAs($this->admin);
        $device = $this->pair();
        $this->store($device, $this->reading($device));
        $channel = collect($device['channels'])->firstWhere('kind', 'temperature');
        $this->patchJson('/api/v1/integrations/bluetooth/links/'.$channel['id'], ['version' => $channel['version'], 'is_enabled' => false])->assertNoContent();
        $this->rejected(fn () => $this->store($device, $this->reading($device)));
        $this->deleteJson(self::ROOT.'/'.$device['id'], ['version' => $device['version']])->assertNoContent();
        $this->rejected(fn () => $this->store($device, $this->reading($device)));
        $this->getJson(self::ROOT)->assertOk()->assertJsonCount(0, 'data');
        $again = $this->pair();
        $this->assertSame($device['id'], $again['id']);
        $this->assertGreaterThan($device['version'], $again['version']);
        $this->assertFalse(collect($again['channels'])->firstWhere('kind', 'temperature')['isEnabled']);
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_invalid_payloads_and_retired_hardware_cannot_create_measurements(): void
    {
        $this->actingAs($this->admin);
        $device = $this->pair();
        foreach ([['battery' => '100.1'], ['battery' => '1.5'], ['air_humidity' => '101'], ['temperature' => '-274']] as $values) {
            $this->rejected(fn () => $this->store($device, $this->reading($device, $values)), 422);
        }
        $this->rejected(fn () => $this->store($device, [...$this->reading($device), 'observed_at' => now()->subHour()->toISOString()]), 422);
        $this->rejected(fn () => $this->store($device, [...$this->reading($device), 'observed_at' => now()->addHour()->toISOString()]), 422);
        Asset::whereKey($device['assetId'])->update(['status' => 'retired']);
        $this->rejected(fn () => $this->store($device, $this->reading($device)));
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_readings_keep_the_assignment_at_observation_time_after_hardware_moves(): void
    {
        $this->actingAs($this->admin);
        $device = $this->pair();
        $first = Site::create(['name' => 'Fictional first site']);
        $second = Site::create(['name' => 'Fictional second site']);
        $asset = Asset::findOrFail($device['assetId']);
        $this->placeAsset($asset, ['site_id' => $first->id]);
        $this->travel(2)->seconds();
        $data = $this->reading($device);
        $this->store($device, $data);
        $this->travel(2)->seconds();
        $this->placeAsset($asset, ['site_id' => $second->id]);
        $sample = Measurement::sole();
        $this->assertSame($first->id, $sample->assignmentContext->snapshot['locations'][0]['id']);
        $this->assertSame($second->id, $asset->fresh()->locations()->first()->site_id);
    }

    public function test_pairing_and_configuration_validation_reject_invalid_or_stale_inputs(): void
    {
        $this->actingAs($this->admin);
        $device = $this->pair();
        $this->actingAs($this->admin);
        $this->postJson(self::ROOT, ['browser_id' => 'x', 'name' => 'x', 'kinds' => ['battery', 'battery']])->assertUnprocessable();
        $this->deleteJson(self::ROOT.'/'.$device['id'], ['version' => 9])->assertConflict();
        $this->patchJson('/api/v1/integrations/bluetooth/links/'.$device['channels'][0]['id'], ['version' => 9, 'is_enabled' => false])->assertConflict();
        $channel = collect($device['channels'])->firstWhere('kind', 'temperature');
        $sensor = Sensor::findOrFail($channel['sensorId']);
        $sensor->measurementType->forceFill(['nature' => 'battery'])->save();
        $this->rejected(fn () => $this->store($device, $this->reading($device)));
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_bluetooth_channels_cannot_be_reused_for_http_or_mqtt_sources(): void
    {
        $this->actingAs($this->admin);
        $device = $this->pair();
        config(['mqtt.enabled' => true, 'mqtt.host' => 'fictional-broker', 'mqtt.port' => 1883, 'mqtt.tls' => false]);
        $sensor = $device['channels'][0]['sensorId'];
        $this->postJson('/api/v1/integrations/http/sources', ['sensor_id' => $sensor, 'expires_days' => 30])->assertConflict();
        $this->postJson('/api/v1/integrations/mqtt/sources', ['sensor_id' => $sensor, 'topic' => 'fictional/ble'])->assertConflict();
        $this->assertDatabaseCount('http_measurement_sources', 0);
        $this->assertDatabaseCount('mqtt_measurement_sources', 0);
    }

    public function test_rollback_refuses_to_drop_recorded_bluetooth_history(): void
    {
        $this->actingAs($this->admin);
        $device = $this->pair();
        $this->store($device, $this->reading($device));
        $migration = require database_path('migrations/2026_11_17_010000_create_bluetooth_devices.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Retain Bluetooth measurement history');
        $migration->down();
    }

    public function test_empty_schema_rollback_and_reapply_preserve_measurement_quality_guards(): void
    {
        $migration = require database_path('migrations/2026_11_17_010000_create_bluetooth_devices.php');
        $address = require database_path('migrations/2026_11_17_020000_add_bluetooth_server_address.php');
        $address->down();
        $migration->down();
        $migration->up();
        $address->up();
        $this->actingAs($this->admin);
        $device = $this->pair();
        $this->store($device, $this->reading($device));
        $this->expectException(QueryException::class);
        DB::table('measurements')->update(['quality' => 'fictional_invalid']);
    }
}
