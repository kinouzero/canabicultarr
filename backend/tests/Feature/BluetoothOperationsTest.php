<?php

namespace Tests\Feature;

use App\Exceptions\MeasurementException;
use App\Jobs\RunBluetoothOperation;
use App\Models\BluetoothDevice;
use App\Models\BluetoothLink;
use App\Models\Measurement;
use App\Models\User;
use App\Services\BluetoothOperations;
use App\Services\BluetoothRadio;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class BluetoothOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const ROOT = '/api/v1/integrations/bluetooth';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['bluetooth.enabled' => true]);
        Queue::fake([RunBluetoothOperation::class]);
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($this->admin);
    }

    private function radio(): MockInterface
    {
        $radio = Mockery::mock(BluetoothRadio::class)->makePartial();
        $this->app->instance(BluetoothRadio::class, $radio);

        return $radio;
    }

    private function discover(): array
    {
        $this->radio()->shouldReceive('perform')->once()->with('discover')->andReturn([['address' => 'AA:BB:CC:DD:EE:02', 'name' => 'Server probe', 'rssi' => -60]]);
        $id = $this->postJson(self::ROOT.'/discovery', ['request_id' => (string) Str::uuid()])->assertAccepted()->json('data.id');
        app(BluetoothOperations::class)->execute($id);
        $data = $this->getJson(self::ROOT.'/operations/'.$id)->assertOk()->assertJsonPath('data.status', 'completed')->json('data');
        $this->assertStringNotContainsString('AA:BB:CC:DD:EE:02', json_encode($data));

        return $data;
    }

    private function sample(): array
    {
        return ['observed_at' => now()->toISOString(), 'readings' => [['kind' => 'battery', 'value' => '84']]];
    }

    private function pair(): array
    {
        $discovery = $this->discover();
        $this->radio()->shouldReceive('perform')->once()->with('read', 'AA:BB:CC:DD:EE:02')->andReturn($this->sample());
        $id = $this->postJson(self::ROOT.'/devices', ['request_id' => (string) Str::uuid(), 'discovery_id' => $discovery['id'], 'candidate_id' => $discovery['data'][0]['id']])->assertAccepted()->json('data.id');
        app(BluetoothOperations::class)->execute($id);

        return $this->getJson(self::ROOT.'/operations/'.$id)->assertOk()->assertJsonPath('data.status', 'completed')->json('data.data');
    }

    public function test_discovery_pairing_and_reading_use_only_server_data_and_keep_addresses_private(): void
    {
        $device = $this->pair();
        $this->assertDatabaseCount('measurements', 1);
        $this->assertDatabaseMissing('bluetooth_devices', ['address' => 'AA:BB:CC:DD:EE:02']);
        $model = BluetoothDevice::sole();
        $this->assertSame('AA:BB:CC:DD:EE:02', $model->address);
        $this->assertArrayNotHasKey('address', $model->toArray());
        $this->travel(1)->seconds();
        $this->radio()->shouldReceive('perform')->once()->with('read', 'AA:BB:CC:DD:EE:02')->andReturn($this->sample());
        $input = ['version' => $device['version'], 'request_id' => (string) Str::uuid()];
        $operation = $this->postJson(self::ROOT.'/devices/'.$device['id'].'/readings', $input)->assertAccepted()->json('data.id');
        app(BluetoothOperations::class)->execute($operation);
        app(BluetoothOperations::class)->execute($operation);
        $this->getJson(self::ROOT.'/operations/'.$operation)->assertOk()->assertJsonPath('data.data.stored', 1);
        $this->postJson(self::ROOT.'/devices/'.$device['id'].'/readings', $input)->assertAccepted()->assertJsonPath('data.id', $operation);
        $this->assertDatabaseCount('measurements', 2);
        $this->assertSame('bluetooth', Measurement::first()->provenance_snapshot['source']['kind']);
    }

    public function test_expired_discovery_and_reused_request_ids_do_not_trigger_a_second_radio_operation(): void
    {
        $discovery = $this->discover();
        $this->radio()->shouldNotReceive('perform');
        $request = (string) Str::uuid();
        Cache::forget('bluetooth:operation:'.$discovery['id']);
        $input = ['request_id' => $request, 'discovery_id' => $discovery['id'], 'candidate_id' => $discovery['data'][0]['id']];
        $id = $this->postJson(self::ROOT.'/devices', $input)->assertAccepted()->json('data.id');
        app(BluetoothOperations::class)->execute($id);
        $this->getJson(self::ROOT.'/operations/'.$id)->assertOk()->assertJsonPath('data.error', 'bluetooth.errors.expired');
        $this->postJson(self::ROOT.'/devices', [...$input, 'candidate_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_browser_measurements_cannot_be_injected_through_the_server_read_endpoint(): void
    {
        $device = $this->pair();
        $this->postJson(self::ROOT.'/devices/'.$device['id'].'/readings', ['request_id' => (string) Str::uuid(), 'version' => 1, ...$this->sample()])->assertUnprocessable();
        $this->assertDatabaseCount('measurements', 1);
        Queue::assertPushed(RunBluetoothOperation::class, 2);
        Queue::assertPushedOn('integrations', RunBluetoothOperation::class);
    }

    public function test_operation_results_and_candidates_are_scoped_to_the_original_user_and_session(): void
    {
        $discovery = $this->discover();
        $other = User::factory()->create(['permission_role_ids' => $this->admin->permission_role_ids]);
        $this->actingAs($other)->getJson(self::ROOT.'/operations/'.$discovery['id'])->assertNotFound();
        $this->radio()->shouldNotReceive('perform');
        $id = $this->postJson(self::ROOT.'/devices', ['request_id' => (string) Str::uuid(), 'discovery_id' => $discovery['id'], 'candidate_id' => $discovery['data'][0]['id']])->assertAccepted()->json('data.id');
        app(BluetoothOperations::class)->execute($id);
        $this->getJson(self::ROOT.'/operations/'.$id)->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_revocation_and_expired_jobs_do_not_access_the_radio(): void
    {
        $this->radio()->shouldNotReceive('perform');
        $id = $this->postJson(self::ROOT.'/discovery', ['request_id' => (string) Str::uuid()])->assertAccepted()->json('data.id');
        $this->admin->increment('session_version');
        app(BluetoothOperations::class)->execute($id);
        $this->assertSame('failed', Cache::get('bluetooth:operation:'.$id)['status']);
        $this->admin->refresh();
        $this->actingAs($this->admin)->withSession(['auth_version' => $this->admin->session_version]);
        $id = $this->postJson(self::ROOT.'/discovery', ['request_id' => (string) Str::uuid()])->assertAccepted()->json('data.id');
        $this->travel(121)->seconds();
        app(BluetoothOperations::class)->execute($id);
        $this->getJson(self::ROOT.'/operations/'.$id)->assertOk()->assertJsonPath('data.error', 'bluetooth.errors.timeout');
    }

    public function test_disabled_server_and_restricted_accounts_cannot_queue_radio_operations(): void
    {
        config(['bluetooth.enabled' => false]);
        $this->postJson(self::ROOT.'/discovery', ['request_id' => (string) Str::uuid()])->assertServiceUnavailable();
        $this->getJson(self::ROOT.'/status')->assertOk()->assertJsonPath('data.enabled', false);
        $user = User::factory()->create(['permission_grants' => ['integrations.manage'], 'location_access' => []]);
        $this->actingAs($user)->getJson(self::ROOT.'/status')->assertForbidden();
        $this->postJson(self::ROOT.'/discovery', ['request_id' => (string) Str::uuid()])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_configuration_changes_during_a_radio_read_prevent_storing_the_sample(): void
    {
        $device = $this->pair();
        $this->travel(1)->seconds();
        $this->radio()->shouldReceive('perform')->once()->andReturnUsing(function () {
            BluetoothLink::query()->increment('version');

            return $this->sample();
        });
        $id = $this->postJson(self::ROOT.'/devices/'.$device['id'].'/readings', ['request_id' => (string) Str::uuid(), 'version' => 1])->assertAccepted()->json('data.id');
        app(BluetoothOperations::class)->execute($id);
        $this->getJson(self::ROOT.'/operations/'.$id)->assertOk()->assertJsonPath('data.error', 'bluetooth.errors.conflict');
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_revocation_during_reading_prevents_measurement_persistence(): void
    {
        $device = $this->pair();
        $this->travel(1)->seconds();
        $this->radio()->shouldReceive('perform')->once()->andReturnUsing(function () {
            User::whereKey($this->admin->id)->increment('session_version');

            return $this->sample();
        });
        $id = $this->postJson(self::ROOT.'/devices/'.$device['id'].'/readings', ['request_id' => (string) Str::uuid(), 'version' => 1])->assertAccepted()->json('data.id');
        app(BluetoothOperations::class)->execute($id);
        $this->assertSame('bluetooth.errors.accessLost', Cache::get('bluetooth:operation:'.$id)['error']);
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_invalid_initial_sample_rolls_back_new_hardware_and_channels(): void
    {
        $discovery = $this->discover();
        $this->radio()->shouldReceive('perform')->once()->andReturn(['observed_at' => now()->toISOString(), 'readings' => [['kind' => 'battery', 'value' => '101']]]);
        $id = $this->postJson(self::ROOT.'/devices', ['request_id' => (string) Str::uuid(), 'discovery_id' => $discovery['id'], 'candidate_id' => $discovery['data'][0]['id']])->assertAccepted()->json('data.id');
        app(BluetoothOperations::class)->execute($id);
        $this->getJson(self::ROOT.'/operations/'.$id)->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('sensors', 0);
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_radio_process_uses_fixed_arguments_json_input_and_sanitized_errors(): void
    {
        Process::fake([Process::result(output: json_encode(['ok' => true, 'data' => []]))]);
        $radio = new BluetoothRadio;
        $this->assertSame([], $radio->perform('discover'));
        Process::assertRan(fn ($process) => $process->command === ['/opt/bluetooth/bin/python', base_path('scripts/bluetooth/bridge.py')]
            && json_decode($process->input, true)['action'] === 'discover');
        Process::fake([Process::result(output: '{broken private driver details', errorOutput: 'AA:BB:CC:DD:EE:02 secret', exitCode: 1)]);
        try {
            $radio->perform('read', 'AA:BB:CC:DD:EE:02');
            $this->fail('Expected an unavailable radio.');
        } catch (MeasurementException $error) {
            $this->assertSame('bluetooth.errors.unavailable', $error->errorCode);
        }
    }
}
