<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledDeviceCommand;
use App\Models\Asset;
use App\Models\DeviceCommand;
use App\Models\HomeAssistantDevice;
use App\Models\HomebridgeDevice;
use App\Models\Site;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\AssetPowerControl;
use App\Services\LocationAccess;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetPowerControlTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $settings;

    private bool $state = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($this->admin);
        config(['home_assistant.url' => 'https://fictional-control.example.test', 'home_assistant.token' => 'fictional-test-token']);
        $this->settings = ['provider' => 'home_assistant', 'entity' => 'light.fixture', 'mode' => 'manual', 'on_at' => null, 'off_at' => null, 'timezone' => 'Europe/Paris'];
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/services/')) {
                $this->state = str_ends_with($request->url(), '/turn_on');

                return Http::response([]);
            }
            if (str_ends_with($request->url(), '/states')) {
                return Http::response([
                    ['entity_id' => 'light.fixture', 'state' => 'off', 'attributes' => ['friendly_name' => 'Fixture light']],
                    ['entity_id' => 'switch.fixture', 'state' => 'on'], ['entity_id' => 'button.fixture', 'state' => 'off'], ['entity_id' => 'sensor.fixture', 'state' => '12'],
                ]);
            }

            $entity = str_ends_with($request->url(), '/states/fan.fixture') ? 'fan.fixture' : 'light.fixture';

            return Http::response(['entity_id' => $entity, 'state' => $this->state ? 'on' : 'off']);
        });
    }

    private function asset(?array $settings = null, string $category = 'light'): Asset
    {
        return app(AssetAdministration::class)->save($this->admin, ['name' => 'Fictional appliance', 'category' => $category, 'power_control' => $settings ?? $this->settings]);
    }

    private function command(Asset $asset, bool $on, ?string $id = null)
    {
        return $this->postJson('/api/v1/assets/'.$asset->id.'/power', ['is_on' => $on, 'request_id' => $id ?? (string) Str::uuid()]);
    }

    public function test_manual_off_preserves_an_automatic_schedule(): void
    {
        $asset = $this->asset([...$this->settings, 'mode' => 'auto', 'on_at' => '10:00', 'off_at' => '12:00']);
        $before = $asset->power_control;
        $this->command($asset, true)->assertOk();
        $this->command($asset, false)->assertOk()->assertJsonPath('data.isOn', false);
        $this->assertFalse($this->state);
        $this->assertSame($before, $asset->fresh()->power_control);
    }

    public function test_manual_commands_are_authorized_idempotent_and_report_actual_state_without_exposing_internal_configuration(): void
    {
        $asset = $this->asset();
        $id = (string) Str::uuid();
        Http::assertNothingSent();
        $this->getJson('/api/v1/assets/'.$asset->id)->assertOk()->assertJsonPath('data.canControlPower', true)->assertJsonMissingPath('data.powerControl.instance_key')->assertJsonMissingPath('data.powerControl.owner_uuid');
        $this->command($asset, true, $id)->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.isOn', true);
        $this->command($asset, true, $id)->assertOk();
        Http::assertSentCount(3);
        $this->getJson('/api/v1/assets/'.$asset->id.'/power')->assertOk()->assertJsonPath('data.isOn', true);
        $this->command($asset, false)->assertOk();
        $this->getJson('/api/v1/assets/'.$asset->id.'/power')->assertJsonPath('data.isOn', false);
        $this->assertDatabaseCount('device_commands', 2);
        $this->command($asset, false, $id)->assertConflict();
    }

    public function test_a_new_command_for_the_current_state_does_not_write_to_the_provider(): void
    {
        $asset = $this->asset();
        $this->command($asset, false)->assertOk()->assertJsonPath('data.isOn', false)
            ->assertJsonPath('data.status', 'confirmed');

        $this->state = true;
        $this->command($asset, true)->assertOk()->assertJsonPath('data.isOn', true)
            ->assertJsonPath('data.status', 'confirmed');

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    #[DataProvider('readBackOutcomes')]
    public function test_only_a_matching_read_back_confirms_an_accepted_command(bool $changes): void
    {
        Sleep::fake();
        $asset = $this->asset();
        Http::swap(new Factory);
        Http::fakeSequence()
            ->push(['entity_id' => 'light.fixture', 'state' => 'off'])
            ->push([])
            ->push(['entity_id' => 'light.fixture', 'state' => 'off'])
            ->push(['entity_id' => 'light.fixture', 'state' => $changes ? 'on' : 'off'])
            ->whenEmpty(Http::response(['entity_id' => 'light.fixture', 'state' => 'off']));

        $id = (string) Str::uuid();
        if ($changes) {
            $this->command($asset, true, $id)->assertOk()->assertJsonPath('data.status', 'confirmed');
        } else {
            $this->command($asset, true, $id)->assertStatus(502)->assertJsonPath('error.code', 'assets.power.uncertain');
            $this->command($asset, true, $id)->assertConflict();
            $this->assertNull(DeviceCommand::findOrFail($id)->confirmed_at);
        }
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/turn_on')));
        Http::assertSentCount($changes ? 4 : 6);
    }

    public static function readBackOutcomes(): array
    {
        return ['delayed transition' => [true], 'device stays off' => [false]];
    }

    public function test_target_discovery_and_input_validation_allow_only_stoppable_controls_and_valid_schedules(): void
    {
        $this->getJson('/api/v1/power-targets?provider=home_assistant')->assertOk()->assertJsonCount(2, 'data');
        foreach ([['entity' => 'button.fixture'], ['mode' => 'auto', 'on_at' => 'bad', 'off_at' => '12:00'], ['mode' => 'auto', 'on_at' => '12:00', 'off_at' => '12:00'], ['mode' => 'auto', 'on_at' => '10:00', 'off_at' => '12:00', 'timezone' => 'bad']] as $override) {
            $this->postJson('/api/v1/assets', ['name' => 'Invalid', 'category' => 'light', 'power_control' => [...$this->settings, ...$override]])->assertUnprocessable();
        }
        $this->postJson('/api/v1/assets', ['name' => 'Wrong category', 'category' => 'pump', 'power_control' => $this->settings])->assertUnprocessable();
    }

    public function test_discovery_limits_home_assistant_targets_to_the_imported_device(): void
    {
        $asset = $this->asset();
        HomeAssistantDevice::create(['asset_id' => $asset->id, 'source_key' => 'device:fixture', 'instance_key' => hash('sha256', config('home_assistant.url'))]);
        Http::swap(new Factory);
        Http::fake([
            '*/api/states' => fn () => Http::response([
                ['entity_id' => 'switch.fixture', 'state' => 'on'],
                ['entity_id' => 'switch.other', 'state' => 'on'],
            ]),
            '*/api/template' => fn () => Http::response([
                ['entity_id' => 'switch.fixture', 'device_id' => 'fixture'],
                ['entity_id' => 'switch.other', 'device_id' => 'other'],
            ]),
        ]);

        foreach (['power-targets', 'pump-targets'] as $endpoint) {
            $this->getJson('/api/v1/'.$endpoint.'?provider=home_assistant&asset_id='.$asset->id)
                ->assertOk()->assertJsonPath('data.0.id', 'switch.fixture')->assertJsonCount(1, 'data');
        }
        $this->getJson('/api/v1/power-targets?provider=homebridge&asset_id='.$asset->id)
            ->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/power-targets?provider=home_assistant&asset_id='.$this->asset()->id)
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_discovery_limits_homebridge_targets_to_the_imported_accessory(): void
    {
        config(['homebridge.url' => 'https://fictional-bridge.example.test', 'homebridge.username' => 'test-user', 'homebridge.password' => 'test-password']);
        $asset = $this->asset();
        HomebridgeDevice::create(['asset_id' => $asset->id, 'source_key' => 'device:'.hash('sha256', 'bridge:2'), 'instance_key' => hash('sha256', config('homebridge.url'))]);
        $service = fn (string $id, string $bridge, int $aid) => [
            'uniqueId' => str_repeat($id, 64),
            'instance' => ['username' => $bridge],
            'aid' => $aid,
            'serviceCharacteristics' => [['type' => 'On', 'canWrite' => true]],
        ];
        Http::swap(new Factory);
        Http::fake([
            '*/api/auth/login' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 300]),
            '*/api/accessories' => Http::response([$service('a', 'bridge', 2), $service('b', 'bridge', 2), $service('c', 'other-bridge', 2), $service('d', 'bridge', 3)]),
        ]);

        $this->getJson('/api/v1/power-targets?provider=homebridge&asset_id='.$asset->id)
            ->assertOk()->assertJsonPath('data.0.id', str_repeat('a', 64).':On')->assertJsonPath('data.1.id', str_repeat('b', 64).':On')->assertJsonCount(2, 'data');
    }

    public function test_ambiguous_provider_result_is_not_replayed_and_is_sanitized(): void
    {
        $asset = $this->asset();
        $id = (string) Str::uuid();
        Http::swap(new Factory);
        Http::fake(fn () => throw new \RuntimeException('private-provider-token'));
        $this->command($asset, true, $id)->assertStatus(502)->assertJsonPath('error.code', 'assets.power.uncertain')->assertDontSee('private-provider-token');
        $this->command($asset, true, $id)->assertConflict();
        $this->assertSame('uncertain', DeviceCommand::findOrFail($id)->status);
        $this->assertDatabaseCount('device_commands', 1);
    }

    public function test_geographic_and_functional_permissions_inactive_status_and_provider_changes_prevent_control(): void
    {
        $site = Site::create(['name' => 'Private site']);
        $asset = $this->asset();
        $this->placeAsset($asset, ['site_id' => $site->id]);
        $reader = User::factory()->create(['location_access' => []]);
        $this->actingAs($reader)->getJson('/api/v1/assets/'.$asset->id.'/power')->assertNotFound();
        $this->command($asset, true)->assertNotFound();
        $reader->forceFill(['location_access' => null])->save();
        app(LocationAccess::class)->clear();
        $this->command($asset, true)->assertForbidden();
        $this->actingAs($this->admin);
        $asset->update(['status' => 'retired']);
        $this->command($asset, true)->assertUnprocessable();
        Http::assertNothingSent();
        $asset->update(['status' => 'active']);
        config(['home_assistant.url' => 'https://other.example.test']);
        $this->command($asset, true)->assertStatus(502);
        Http::assertNothingSent();
    }

    public function test_fixed_schedules_use_timezone_and_deduplicate_repeated_dst_hour_without_replaying_expired_jobs(): void
    {
        Queue::fake();
        $asset = $this->asset([...$this->settings, 'mode' => 'auto', 'on_at' => '02:30', 'off_at' => '04:30']);
        $power = app(AssetPowerControl::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-25T00:30:00Z'));
        $power->enqueueDue();
        $jobs = Queue::pushed(RunScheduledDeviceCommand::class);
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $job->handle($power);
        $this->travelTo(CarbonImmutable::parse('2026-10-25T01:30:00Z'));
        $power->enqueueDue();
        $jobs = Queue::pushed(RunScheduledDeviceCommand::class);
        $this->assertCount(2, $jobs);
        $jobs[1]->handle($power);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('device_commands', 1);
        $this->travelTo(CarbonImmutable::parse('2026-10-25T03:30:00Z'));
        $power->enqueueDue();
        $jobs = Queue::pushed(RunScheduledDeviceCommand::class);
        $this->assertCount(3, $jobs);
        $this->travel(2)->minutes();
        $jobs[2]->handle($power);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('device_commands', 1);
    }

    public function test_imported_hardware_rejects_controls_from_a_different_connection(): void
    {
        $instance = hash('sha256', config('home_assistant.url'));
        $asset = $this->asset(['provider' => 'homebridge', 'entity' => str_repeat('a', 64).':On', 'mode' => 'manual']);
        HomeAssistantDevice::create(['asset_id' => $asset->id, 'instance_key' => $instance, 'source_key' => 'device:fixture']);
        $this->getJson('/api/v1/assets/'.$asset->id)->assertOk()->assertJsonPath('data.importedControlProvider', 'home_assistant');
        $this->patchJson('/api/v1/assets/'.$asset->id, ['power_control' => ['provider' => 'homebridge', 'entity' => str_repeat('a', 64).':On', 'mode' => 'manual']])
            ->assertUnprocessable()->assertJsonPath('error.code', 'assets.power.connectionMismatch');
        $this->command($asset, true)->assertUnprocessable()->assertJsonPath('error.code', 'assets.power.connectionMismatch');
        Http::assertNothingSent();
        $this->patchJson('/api/v1/assets/'.$asset->id, ['power_control' => $this->settings])
            ->assertOk()->assertJsonPath('data.powerControl.provider', 'home_assistant');

        $pump = Asset::create(['name' => 'Fixture pump', 'category' => 'pump']);
        HomeAssistantDevice::create(['asset_id' => $pump->id, 'instance_key' => $instance, 'source_key' => 'device:pump']);
        $this->patchJson('/api/v1/assets/'.$pump->id, ['pump_control' => ['provider' => 'homebridge', 'entity' => str_repeat('b', 64).':On', 'mode' => 'auto']])
            ->assertUnprocessable()->assertJsonPath('error.code', 'assets.power.connectionMismatch');
        $this->patchJson('/api/v1/assets/'.$pump->id, ['pump_control' => ['provider' => 'home_assistant', 'entity' => 'switch.fixture', 'mode' => 'auto']])
            ->assertOk()->assertJsonPath('data.pumpControl.provider', 'home_assistant');
    }

    public function test_fans_support_manual_commands_and_fixed_schedules(): void
    {
        Queue::fake();
        $asset = Asset::create(['name' => 'Fixture fan', 'category' => 'fan']);
        HomeAssistantDevice::create(['asset_id' => $asset->id, 'source_key' => 'device:fixture', 'instance_key' => hash('sha256', config('home_assistant.url'))]);
        $this->getJson('/api/v1/assets')->assertOk()->assertJsonPath('data.0.integrations', ['home_assistant']);
        $this->patchJson('/api/v1/assets/'.$asset->id, ['power_control' => [...$this->settings, 'entity' => 'fan.fixture', 'mode' => 'auto', 'on_at' => '10:00', 'off_at' => '12:00']])
            ->assertOk()->assertJsonPath('data.canControlPower', true)->assertJsonPath('data.integrations', ['home_assistant']);
        $this->getJson('/api/v1/assets/'.$asset->id)->assertOk()->assertJsonPath('data.canControlPower', true)->assertJsonPath('data.integrations', ['home_assistant']);
        $asset->refresh();

        $power = app(AssetPowerControl::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-18T09:00:00Z'));
        $power->enqueueDue();
        $jobs = Queue::pushed(RunScheduledDeviceCommand::class);
        $this->assertCount(1, $jobs);
        $jobs[0]->handle($power);
        $this->getJson('/api/v1/assets/'.$asset->id.'/power')->assertOk()->assertJsonPath('data.isOn', true);
        $this->command($asset, false)->assertOk()->assertJsonPath('data.isOn', false);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/services/fan/turn_off'));
    }

    public function test_queued_work_rechecks_configuration_and_owner_permissions(): void
    {
        $asset = $this->asset([...$this->settings, 'mode' => 'auto', 'on_at' => '10:00', 'off_at' => '11:00']);
        $power = app(AssetPowerControl::class);
        $revision = $asset->power_control['revision'];
        $asset->update(['power_control' => [...$asset->power_control, 'revision' => (string) Str::uuid()]]);
        $power->scheduled($asset->id, $revision, true, hash('sha256', 'slot'), now()->toISOString());
        Http::assertNothingSent();
        $asset->update(['power_control' => [...$asset->power_control, 'revision' => $revision]]);
        $this->admin->forceFill(['permission_role_ids' => []])->save();
        app(LocationAccess::class)->clear();
        $this->expectException(AuthorizationException::class);
        try {
            $power->scheduled($asset->id, $revision, true, hash('sha256', 'slot'), now()->toISOString());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_binding_requires_integration_permission_but_existing_controls_allow_hardware_managers_to_set_schedules(): void
    {
        $asset = $this->asset();
        $manager = User::factory()->create(['permission_grants' => ['assets.manage']]);
        $this->actingAs($manager)->getJson('/api/v1/power-targets?provider=home_assistant')->assertForbidden();
        $this->postJson('/api/v1/assets', ['name' => 'Forbidden binding', 'category' => 'light', 'power_control' => $this->settings])->assertForbidden();
        $this->patchJson('/api/v1/assets/'.$asset->id, ['power_control' => [...$this->settings, 'mode' => 'auto', 'on_at' => '10:00', 'off_at' => '10:05']])->assertOk()->assertJsonPath('data.powerControl.mode', 'auto');
        $this->command($asset, true)->assertOk();
        $this->patchJson('/api/v1/assets/'.$asset->id, ['power_control' => [...$this->settings, 'entity' => 'switch.different']])->assertForbidden();
    }

    public function test_pending_commands_block_parallel_writes_and_stale_pending_records_are_not_replayed(): void
    {
        $asset = $this->asset();
        $id = (string) Str::uuid();
        DeviceCommand::create(['id' => $id, 'asset_id' => $asset->id, 'actor_uuid' => $this->admin->uuid, 'schedule_key' => null,
            'is_on' => true, 'status' => 'pending', 'control' => $asset->power_control, 'created_at' => now()]);
        $this->command($asset, false)->assertConflict();
        $this->patchJson('/api/v1/assets/'.$asset->id, ['power_control' => null])->assertConflict();
        Http::assertNothingSent();
        $this->travel(121)->seconds();
        $this->getJson('/api/v1/assets/'.$asset->id.'/power')->assertOk()->assertJsonPath('data.lastCommand.status', 'uncertain');
        $this->command($asset, true, $id)->assertConflict();
        $this->assertSame('uncertain', DeviceCommand::findOrFail($id)->status);
        $this->command($asset, false)->assertOk();
        $this->assertDatabaseCount('device_commands', 2);
    }

    public function test_homebridge_commands_only_write_the_requested_characteristic(): void
    {
        config(['homebridge.url' => 'https://bridge.example.test']);
        $this->configureHomebridge('https://bridge.example.test', 'fictional');
        $entity = str_repeat('a', 64).':On';
        $asset = $this->asset([...$this->settings, 'provider' => 'homebridge', 'entity' => $entity], 'socket');
        Http::swap(new Factory);
        Http::fakeSequence()
            ->push(['uniqueId' => str_repeat('a', 64), 'serviceCharacteristics' => [['type' => 'On', 'canWrite' => true, 'status' => 0, 'value' => false]]])
            ->push([])
            ->push(['uniqueId' => str_repeat('a', 64), 'serviceCharacteristics' => [['type' => 'On', 'canWrite' => true, 'status' => 0, 'value' => true]]]);
        $this->command($asset, true)->assertOk()->assertJsonPath('data.isOn', true);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['characteristicType'] === 'On' && $request['value'] === true);
    }

    public static function homebridgeStates(): array
    {
        return [
            'on' => [true, true, 0, true],
            'off' => [0, true, 0, false],
            'unknown value' => ['unavailable', true, 0, null],
            'read-only' => [true, false, 0, null],
            'unavailable' => [true, true, 1, null],
        ];
    }

    #[DataProvider('homebridgeStates')]
    public function test_homebridge_power_reads_use_actual_values_and_never_issue_commands(mixed $value, bool $writable, int $status, ?bool $expected): void
    {
        config(['homebridge.url' => 'https://bridge.example.test']);
        $this->configureHomebridge('https://bridge.example.test', 'fictional');
        $asset = $this->asset([...$this->settings, 'provider' => 'homebridge', 'entity' => str_repeat('a', 64).':On'], 'socket');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['uniqueId' => str_repeat('a', 64), 'serviceCharacteristics' => [['type' => 'On', 'canWrite' => $writable, 'status' => $status, 'value' => $value]]]));
        $this->getJson('/api/v1/assets/'.$asset->id.'/power')->assertOk()->assertJsonPath('data.isOn', $expected);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertDatabaseCount('device_commands', 0);
    }
}
