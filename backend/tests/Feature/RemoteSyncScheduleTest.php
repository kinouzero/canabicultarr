<?php

namespace Tests\Feature;

use App\Jobs\CollectRemoteSensor;
use App\Models\HomeAssistantLink;
use App\Models\Site;
use App\Models\User;
use App\Services\LocationAccess;
use App\Services\RemoteSensorCollection;
use App\Services\RemoteSensorImport;
use App\Services\RemoteSyncSchedule;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RemoteSyncScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['home_assistant.url' => 'https://ha.example.test', 'home_assistant.token' => 'fictional-token', 'home_assistant.sync_enabled' => true, 'home_assistant.sync_interval_seconds' => 300]);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function link(): HomeAssistantLink
    {
        $state = ['entity_id' => 'sensor.temperature', 'state' => '21.5', 'attributes' => ['friendly_name' => 'Probe', 'unit_of_measurement' => '°C', 'device_class' => 'temperature'], 'last_reported' => now()->subSecond()->toISOString()];
        Http::fake(['https://ha.example.test/api/states' => Http::response([$state]), 'https://ha.example.test/api/template' => Http::response([['entity_id' => 'sensor.temperature', 'device_id' => 'probe', 'name' => 'Probe']]), 'https://ha.example.test/api/states/*' => Http::response($state)]);
        app(RemoteSensorImport::class)->import($this->admin, ['sensor.temperature']);

        return HomeAssistantLink::sole();
    }

    public function test_due_boundary_and_provider_independence(): void
    {
        Queue::fake();
        $link = $this->link();
        $link->update(['last_attempt_at' => now()->subSeconds(299)]);
        $collection = app(RemoteSensorCollection::class);
        $this->assertSame(0, $collection->batch('ha'));
        $this->travel(1)->seconds();
        $this->assertSame(1, $collection->batch('ha'));
        Queue::assertPushed(CollectRemoteSensor::class, 1);
        config(['homebridge.sync_interval_seconds' => 120]);
        $this->assertSame(300, app(RemoteSyncSchedule::class)->configuration('ha')['intervalSeconds']);
        $this->assertSame(120, app(RemoteSyncSchedule::class)->configuration('hb')['intervalSeconds']);
    }

    public function test_pending_automatic_work_rechecks_pause_and_interval_but_manual_collection_remains_available(): void
    {
        $link = $this->link();
        $job = new CollectRemoteSensor('ha', $link->id, $link->version, now()->toISOString());
        $link->update(['last_attempt_at' => now()]);
        $sent = count(Http::recorded());
        $job->handle(app(RemoteSensorCollection::class));
        $this->assertCount($sent, Http::recorded());
        $link->update(['last_attempt_at' => null]);
        config(['home_assistant.sync_enabled' => false]);
        $job->handle(app(RemoteSensorCollection::class));
        $this->assertCount($sent, Http::recorded());
        (new CollectRemoteSensor('ha', $link->id, $link->version, now()->toISOString(), $this->admin->uuid, $this->admin->session_version))->handle(app(RemoteSensorCollection::class));
        $this->assertCount($sent + 1, Http::recorded());
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_status_and_link_round_the_persisted_attempt_consistently_at_a_fractional_minute_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T12:00:00.500000Z'));
        $link = $this->link();
        $link->update(['last_attempt_at' => now()->subMinute()]);
        $root = '/api/v1/integrations/home-assistant';
        $expected = '2026-11-17T12:04:00.000000Z';
        $this->actingAs($this->admin)->getJson($root)->assertOk()->assertJsonPath('data.sync.nextEligibleAt', $expected);
        $this->getJson($root.'/links')->assertOk()->assertJsonPath('data.0.nextSyncAt', $expected);
    }

    public function test_status_and_link_schedule_are_scoped_and_disabled_sources_are_excluded(): void
    {
        $link = $this->link();
        $link->update(['last_attempt_at' => now()->subSeconds(60), 'last_success_at' => now()->subSeconds(120)]);
        $root = '/api/v1/integrations/home-assistant';
        $this->actingAs($this->admin)->getJson($root)->assertOk()->assertJsonPath('data.sync.linksCount', 1)->assertJsonPath('data.sync.nextEligibleAt', now()->addSeconds(240)->toImmutable()->ceilMinute()->toISOString());
        $this->getJson($root.'/links')->assertOk()->assertJsonPath('data.0.nextSyncAt', now()->addSeconds(240)->toImmutable()->ceilMinute()->toISOString());
        $limited = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('observer')->id], 'permission_grants' => ['integrations.manage'], 'location_access' => [['type' => 'site', 'id' => Site::create(['name' => 'Other'])->id]]]);
        app(LocationAccess::class)->clear();
        $this->actingAs($limited)->getJson($root)->assertOk()->assertJsonPath('data.sync.linksCount', 0)->assertJsonPath('data.sync.lastSuccessAt', null)->assertJsonPath('data.sync.nextEligibleAt', null);
        $this->actingAs($this->admin);
        app(LocationAccess::class)->clear();
        $link->sensor->update(['is_enabled' => false]);
        $this->getJson($root)->assertOk()->assertJsonPath('data.sync.linksCount', 0);
        $this->assertSame(0, app(RemoteSensorCollection::class)->batch('ha'));
    }

    public static function invalidIntervals(): array
    {
        return [[0], [59], [61], [86460], ['bad']];
    }

    #[DataProvider('invalidIntervals')]
    public function test_invalid_environment_interval_fails_closed(mixed $value): void
    {
        config(['home_assistant.sync_interval_seconds' => $value]);
        $this->assertSame(['enabled' => false, 'configurationValid' => false, 'intervalSeconds' => null], app(RemoteSyncSchedule::class)->configuration('ha'));
        Queue::fake();
        $this->assertSame(0, app(RemoteSensorCollection::class)->batch('ha'));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_interval_settings_validate_persist_and_honor_environment_locks(): void
    {
        $root = '/api/v1/connection-settings/home_assistant';
        foreach (['HOME_ASSISTANT_SYNC_ENABLED', 'HOME_ASSISTANT_SYNC_INTERVAL_SECONDS'] as $name) {
            config(['connection_settings.home_assistant.'.$name.'.locked' => false]);
        }
        $this->actingAs($this->admin);
        foreach ([59, 61, 86460, 'no'] as $interval) {
            $this->patchJson($root, ['version' => 1, 'values' => ['HOME_ASSISTANT_SYNC_INTERVAL_SECONDS' => $interval]])->assertUnprocessable();
        }
        $this->patchJson($root, ['version' => 1, 'values' => ['HOME_ASSISTANT_SYNC_ENABLED' => false, 'HOME_ASSISTANT_SYNC_INTERVAL_SECONDS' => 86400]])->assertOk();
        $this->assertFalse(config('home_assistant.sync_enabled'));
        $this->assertSame(86400, config('home_assistant.sync_interval_seconds'));
        config(['connection_settings.home_assistant.HOME_ASSISTANT_SYNC_INTERVAL_SECONDS.locked' => true]);
        $this->patchJson($root, ['version' => 2, 'values' => ['HOME_ASSISTANT_SYNC_INTERVAL_SECONDS' => 120]])->assertUnprocessable();
    }
}
