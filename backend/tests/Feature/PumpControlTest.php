<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Plant;
use App\Models\PumpActivation;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\ConnectionConfiguration;
use App\Services\HomeAssistantClient;
use App\Services\HomebridgeClient;
use App\Services\PlantTracking;
use App\Services\PumpControl;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PumpControlTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Asset $pump;

    private Plant $plant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['home_assistant.url' => 'https://ha.example.test', 'home_assistant.token' => 'test-token', 'homebridge.url' => 'https://hb.example.test']);
        $this->configureHomebridge('https://hb.example.test', 'test-token');
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($this->admin);
        $site = Site::create(['name' => 'Synthetic pump site']);
        $this->pump = Asset::create(['name' => 'Synthetic pump', 'category' => 'pump', 'site_id' => $site->id, 'pump_control' => ['instance_key' => app(HomeAssistantClient::class)->instanceKey(), 'provider' => 'home_assistant', 'entity' => 'switch.pump', 'mode' => 'auto', 'seconds' => null]]);
        $this->plant = app(PlantTracking::class)->create($this->admin, ['name' => 'Synthetic pump plant', 'request_id' => (string) Str::uuid(), 'strain_id' => Strain::create(['name' => 'Synthetic pump strain'])->id, 'location' => ['type' => 'site', 'id' => $site->id], 'device_ids' => [$this->pump->id]]);
        $this->plant->update(['phase' => 'growth']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $on = false;
        Http::fake(['https://ha.example.test/api/*' => function ($request) use (&$on) {
            if ($request->method() === 'POST') {
                $on = str_ends_with($request->url(), '/turn_on');

                return Http::response([]);
            }

            return Http::response(['entity_id' => 'switch.pump', 'state' => $on ? 'on' : 'off']);
        }]);
    }

    private function activate(?string $id = null)
    {
        return $this->postJson('/api/v1/assets/'.$this->pump->id.'/activate', ['plant_id' => $this->plant->id, 'request_id' => $id ?? (string) Str::uuid()]);
    }

    private function mode(string $mode, ?int $seconds = null): void
    {
        $this->pump->update(['pump_control' => [...$this->pump->pump_control, 'mode' => $mode, 'seconds' => $seconds]]);
    }

    public function test_pump_choices_are_searchable_and_paginated_without_loading_all_plants(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $plant = $this->plant->replicate();
            $plant->creation_key = (string) Str::uuid();
            $plant->name = sprintf('Paged plant %02d', $i);
            $plant->save();
            $plant->devices()->attach($this->pump);
        }
        $path = '/api/v1/assets/'.$this->pump->id.'/pump-context';
        $this->getJson($path)->assertOk()->assertJsonCount(50, 'data.plants')->assertJsonPath('data.plantsMeta.total', 61);
        $this->getJson($path.'?page=2')->assertOk()->assertJsonCount(11, 'data.plants');
        $this->getJson($path.'?search=Paged%20plant%2059')->assertOk()->assertJsonCount(1, 'data.plants')->assertJsonPath('data.plants.0.name', 'Paged plant 59');
    }

    public function test_persistent_timer_worker_processes_durable_stops_once_without_restarting_a_pump(): void
    {
        $this->mode('timer', 1);
        $this->activate()->assertOk();
        $this->travel(2)->seconds();
        $connections = \Mockery::mock(ConnectionConfiguration::class);
        $connections->shouldReceive('apply')->twice();
        $this->app->instance(ConnectionConfiguration::class, $connections);
        $this->artisan('pumps:work', ['--once' => true])->assertSuccessful();
        $this->artisan('pumps:work', ['--once' => true])->assertSuccessful();
        Http::assertSentCount(6);
        $this->assertSame('confirmed', PumpActivation::sole()->stop_status);
        $this->assertDatabaseCount('pump_activations', 1);
    }

    public function test_start_is_idempotent_and_watering_is_recorded_separately_with_pump_history(): void
    {
        $id = (string) Str::uuid();
        $this->activate($id)->assertOk()->assertJsonPath('data.id', $id);
        $this->activate($id)->assertOk();
        Http::assertSentCount(2); // One state read and exactly one physical command.
        $this->assertDatabaseCount('plant_events', 1);
        $this->getJson('/api/v1/assets/'.$this->pump->id.'/pump-context')->assertOk()->assertJsonPath('data.plants.0.activation.id', $id);
        $input = ['version' => $this->plant->fresh()->version, 'request_id' => $id, 'with_products' => false];
        $this->postJson('/api/v1/plants/'.$this->plant->id.'/waterings', $input)->assertOk();
        $this->postJson('/api/v1/plants/'.$this->plant->id.'/waterings', $input)->assertOk();
        $this->assertDatabaseCount('plant_events', 2);
        $this->getJson('/api/v1/plants/'.$this->plant->id.'/events')->assertOk()->assertJsonPath('data.0.watering.pump.name', 'Synthetic pump');
        $this->getJson('/api/v1/assets/'.$this->pump->id.'/pump-context')->assertJsonPath('data.plants.0.activation', null);
    }

    public function test_unrecorded_activation_does_not_require_watering_before_starting_again(): void
    {
        $this->activate()->assertOk();
        $this->travel(1)->seconds();
        $next = (string) Str::uuid();
        $this->activate($next)->assertOk();
        $this->assertDatabaseCount('pump_activations', 2);
        $this->assertDatabaseCount('plant_events', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->getJson('/api/v1/assets/'.$this->pump->id.'/pump-context')->assertJsonPath('data.plants.0.activation.id', $next);
        Http::assertSentCount(4);
    }

    public function test_auto_pump_can_be_stopped_manually_without_watering_or_changing_mode(): void
    {
        $id = (string) Str::uuid();
        $this->activate($id)->assertOk();
        $this->getJson('/api/v1/assets/'.$this->pump->id.'/pump-context')->assertOk()
            ->assertJsonPath('data.running', null)->assertJsonPath('data.stoppable.id', $id);
        $url = '/api/v1/assets/'.$this->pump->id.'/pump-activations/'.$id.'/stop';
        $this->postJson($url)->assertOk()->assertJsonPath('data.stopStatus', 'confirmed');
        $this->postJson($url)->assertOk();
        Http::assertSentCount(5);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/turn_off'));
        $this->getJson('/api/v1/assets/'.$this->pump->id.'/pump-context')->assertJsonPath('data.stoppable', null);
        $this->assertSame('auto', $this->pump->fresh()->pump_control['mode']);
        $this->assertDatabaseCount('plant_events', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->travel(1)->seconds();
        $next = (string) Str::uuid();
        $this->activate($next)->assertOk();
        $this->getJson('/api/v1/assets/'.$this->pump->id.'/pump-context')->assertJsonPath('data.stoppable.id', $next);
    }

    public function test_on_only_auto_pump_does_not_offer_or_send_an_unsupported_stop(): void
    {
        $this->pump->update(['pump_control' => [...$this->pump->pump_control, 'entity' => 'button.pump']]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://ha.example.test/api/states/*' => Http::response(['entity_id' => 'button.pump', 'state' => 'off']), 'https://ha.example.test/api/services/button/press' => Http::response([])]);
        $id = $this->activate()->assertOk()->json('data.id');
        $this->getJson('/api/v1/assets/'.$this->pump->id.'/pump-context')->assertJsonPath('data.stoppable', null);
        $this->postJson('/api/v1/assets/'.$this->pump->id.'/pump-activations/'.$id.'/stop')->assertUnprocessable()->assertJsonPath('error.code', 'assets.pump.notStoppable');
        Http::assertSentCount(2);
    }

    public function test_pump_context_hydrates_only_the_latest_unrecorded_activation_and_status_omits_plants(): void
    {
        $id = (string) Str::uuid();
        $this->activate($id)->assertOk();
        $template = PumpActivation::findOrFail($id)->getAttributes();
        $rows = [];
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = [...$template, 'id' => (string) Str::uuid(), 'created_at' => now()->subDay()->format('Y-m-d H:i:s.u')];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('pump_activations')->insert($chunk);
        }
        $hydrated = 0;
        PumpActivation::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        $this->getJson('/api/v1/assets/'.$this->pump->id.'/pump-context')->assertOk()->assertJsonPath('data.plants.0.activation.id', $id)->assertJsonPath('data.plantsMeta.per_page', 50);
        $this->assertLessThanOrEqual(2, $hydrated);
        $this->getJson('/api/v1/assets/'.$this->pump->id.'/pump-status')->assertOk()->assertJsonMissingPath('data.plants')->assertJsonPath('data.mode', 'auto');
        $this->assertDatabaseCount('plant_events', 1);
    }

    public function test_timer_persists_and_stops_only_after_due_time_even_if_the_plant_was_deleted(): void
    {
        $this->mode('timer', 10);
        $id = (string) Str::uuid();
        $this->activate($id)->assertOk();
        $this->plant->devices()->detach();
        $this->plant->events()->delete();
        $this->plant->delete();
        app(PumpControl::class)->stopDue();
        Http::assertSentCount(3);
        $this->travel(11)->seconds();
        app(PumpControl::class)->stopDue();
        Http::assertSent(fn ($r) => $r->url() === 'https://ha.example.test/api/services/switch/turn_off' && $r['entity_id'] === 'switch.pump');
        $this->assertNotNull(PumpActivation::findOrFail($id)->stopped_at);
        app(PumpControl::class)->stopDue();
        Http::assertSentCount(6);
    }

    public function test_manual_stop_is_idempotent_and_running_pump_blocks_new_starts_and_reconfiguration(): void
    {
        $this->mode('manual');
        $id = (string) Str::uuid();
        $this->activate($id)->assertOk();
        $this->activate()->assertConflict();
        $this->patchJson('/api/v1/assets/'.$this->pump->id, ['category' => 'fan'])->assertConflict()->assertJsonPath('error.code', 'assets.pump.running');
        $url = '/api/v1/assets/'.$this->pump->id.'/pump-activations/'.$id.'/stop';
        $this->postJson($url)->assertOk()->assertJsonPath('data.stopStatus', 'confirmed');
        $this->postJson($url)->assertOk();
        Http::assertSentCount(6);
    }

    public function test_ambiguous_start_is_never_repeated_and_timer_still_stops(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://ha.example.test/api/states/*' => fn () => Http::response(['entity_id' => 'switch.pump', 'state' => 'off']), 'https://ha.example.test/api/services/switch/turn_on' => fn () => Http::response([], 503), 'https://ha.example.test/api/services/switch/turn_off' => fn () => Http::response([])]);
        $this->mode('timer', 1);
        $id = (string) Str::uuid();
        $this->activate($id)->assertStatus(502)->assertJsonPath('error.code', 'assets.pump.uncertain');
        $this->activate($id)->assertConflict();
        Http::assertSentCount(2);
        $this->travel(2)->seconds();
        app(PumpControl::class)->stopDue();
        $this->assertNotNull(PumpActivation::findOrFail($id)->stopped_at);
    }

    public function test_permissions_assignment_stage_and_target_validation_block_commands(): void
    {
        $this->plant->update(['phase' => 'sowing']);
        $this->activate()->assertUnprocessable();
        $this->plant->update(['phase' => 'growth']);
        $this->plant->devices()->detach();
        $this->activate()->assertUnprocessable();
        $this->patchJson('/api/v1/assets/'.$this->pump->id, ['pump_control' => ['provider' => 'home_assistant', 'entity' => 'sensor.moisture', 'mode' => 'auto']])->assertUnprocessable();
        $this->patchJson('/api/v1/assets/'.$this->pump->id, ['pump_control' => ['provider' => 'home_assistant', 'entity' => 'button.pump', 'mode' => 'manual']])->assertUnprocessable();
        $this->patchJson('/api/v1/assets/'.$this->pump->id, ['pump_control' => ['provider' => 'home_assistant', 'entity' => 'switch.pump', 'mode' => 'timer', 'seconds' => 0]])->assertUnprocessable();
        $this->patchJson('/api/v1/assets/'.$this->pump->id, ['pump_control' => ['provider' => 'home_assistant', 'entity' => 'switch.pump', 'mode' => 'timer', 'seconds' => 45]])->assertOk()->assertJsonPath('data.pumpControl.seconds', 45)->assertJsonMissingPath('data.pumpControl.instance_key');
        $this->assertSame(app(HomeAssistantClient::class)->instanceKey(), $this->pump->fresh()->pump_control['instance_key']);
        $observer = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('observer')->id]]);
        $this->actingAs($observer);
        $this->activate()->assertForbidden();
        $this->getJson('/api/v1/pump-targets?provider=home_assistant')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_homebridge_uses_writable_characteristics_and_put_for_start_and_stop(): void
    {
        $id = str_repeat('a', 64);
        $this->pump->update(['pump_control' => ['instance_key' => app(HomebridgeClient::class)->instanceKey(), 'provider' => 'homebridge', 'entity' => $id.':On', 'mode' => 'manual', 'seconds' => null]]);
        $service = ['uniqueId' => $id, 'serviceName' => 'Synthetic pump', 'serviceCharacteristics' => [['type' => 'On', 'canWrite' => true, 'status' => 0, 'value' => false], ['type' => 'CurrentTemperature', 'canWrite' => false]]];
        Http::fake([
            'https://hb.example.test/api/accessories' => fn () => Http::response([$service]),
            'https://hb.example.test/api/accessories/*' => function ($request) use (&$service) {
                if ($request->method() === 'PUT') {
                    $service['serviceCharacteristics'][0]['value'] = $request['value'];

                    return Http::response([]);
                }

                return Http::response($service);
            },
        ]);
        $this->getJson('/api/v1/pump-targets?provider=homebridge')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id.':On');
        $activation = $this->activate()->assertOk()->json('data.id');
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['characteristicType'] === 'On' && $r['value'] === true);
        $this->postJson('/api/v1/assets/'.$this->pump->id.'/pump-activations/'.$activation.'/stop')->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['value'] === false);
    }

    public function test_failed_timer_stop_is_persisted_then_retried_without_another_start(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $fail = true;
        $on = false;
        Http::fake(function ($request) use (&$fail, &$on) {
            if (str_contains($request->url(), '/states/')) {
                return Http::response(['entity_id' => 'switch.pump', 'state' => $on ? 'on' : 'off']);
            }
            if (str_ends_with($request->url(), '/turn_off') && $fail) {
                return Http::response([], 503);
            }
            $on = str_ends_with($request->url(), '/turn_on');

            return Http::response([]);
        });
        $this->mode('timer', 1);
        $id = (string) Str::uuid();
        $this->activate($id)->assertOk();
        $this->travel(2)->seconds();
        app(PumpControl::class)->stopDue();
        $this->assertSame('failed', PumpActivation::findOrFail($id)->stop_status);
        $this->assertNull(PumpActivation::findOrFail($id)->stopped_at);
        $this->travel(31)->seconds();
        $fail = false;
        app(PumpControl::class)->stopDue();
        $this->assertNotNull(PumpActivation::findOrFail($id)->stopped_at);
        $commands = Http::recorded(fn ($request) => str_ends_with($request->url(), '/turn_on'));
        $this->assertCount(1, $commands);
    }

    public function test_changed_provider_instance_and_reused_event_ids_never_send_a_start(): void
    {
        $usedId = (string) Str::uuid();
        $this->postJson('/api/v1/plants/'.$this->plant->id.'/waterings', ['version' => $this->plant->version, 'request_id' => $usedId, 'with_products' => false])->assertOk();
        config(['home_assistant.url' => 'https://other.example.test']);
        $this->activate()->assertStatus(502);
        Http::assertNothingSent();
        $this->activate($usedId)->assertConflict();
        Http::assertNothingSent();
    }
}
