<?php

namespace Tests\Feature;

use App\Models\LifecycleSetting;
use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\LifecycleDefinition;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlantWateringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private Strain $strain;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise watering on an explicitly configured stage; sowing is disabled by default.
        $settings = LifecycleSetting::findOrFail(1);
        $stages = $settings->stages;
        $stages[1]['watering'] = true;
        $settings->update(['stages' => $stages]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->site = Site::create(['name' => 'Fictional Watering Site']);
        $this->strain = Strain::create(['name' => 'Fictional Watering Strain']);
    }

    private function plant(): Plant
    {
        return app(PlantTracking::class)->create($this->admin, ['name' => 'Fictional Plant', 'request_id' => (string) Str::uuid(), 'strain_id' => $this->strain->id, 'location' => ['type' => 'site', 'id' => $this->site->id]]);
    }

    private function action(Plant $plant, string $action, array $extra = []): Plant
    {
        return app(PlantTracking::class)->action($this->admin, $plant, ['action' => $action, 'version' => $plant->version, 'request_id' => (string) Str::uuid(), ...$extra]);
    }

    private function flowering(): Plant
    {
        $plant = $this->plant();
        foreach (['plant', 'repot_growth', 'repot_flowering'] as $action) {
            $plant = $this->action($plant, $action);
        }

        return $plant;
    }

    private function input(Plant $plant, bool $withProducts = true): array
    {
        return ['version' => $plant->version, 'request_id' => (string) Str::uuid(), 'with_products' => $withProducts];
    }

    private function url(Plant $plant): string
    {
        return '/api/v1/plants/'.$plant->id.'/waterings';
    }

    public function test_default_sowing_has_no_watering_and_germination_enables_it(): void
    {
        $settings = LifecycleSetting::findOrFail(1);
        $settings->update(['stages' => LifecycleDefinition::defaults()]);
        request()->attributes->remove('lifecycle.stages');
        $plant = $this->plant();
        $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.watering.status', 'unavailable');
        $this->postJson($this->url($plant), $this->input($plant))->assertConflict();
        $this->postJson('/api/v1/plants/'.$plant->id.'/maintenance', [...$this->input($plant), 'type' => 'pruning'])->assertConflict();
        foreach (['hanging_drying', 'rack_drying'] as $phase) {
            $plant->forceFill(['phase' => $phase])->save();
            $this->postJson('/api/v1/plants/'.$plant->id.'/maintenance', [...$this->input($plant), 'type' => 'pruning'])->assertConflict();
        }
        $plant->forceFill(['phase' => 'sowing'])->save();
        $plant = $this->action($plant, 'plant');
        $this->postJson($this->url($plant), $this->input($plant, false))->assertOk();
    }

    public function test_access_requires_current_active_administrator_and_plant_scope(): void
    {
        $plant = $this->plant();
        $this->postJson($this->url($plant), $this->input($plant))->assertUnauthorized();
        $member = User::factory()->create();
        $this->actingAs($member)->postJson($this->url($plant), $this->input($plant))->assertForbidden();
        $other = Site::create(['name' => 'Other Fictional Site']);
        $restricted = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id], 'location_access' => [['type' => 'site', 'id' => $other->id]]]);
        $this->actingAs($restricted)->postJson($this->url($plant), $this->input($plant))->assertNotFound();
        $this->assertDatabaseCount('plant_events', 1);
    }

    public function test_explicit_choice_version_key_and_valid_timestamp_are_required(): void
    {
        $plant = $this->plant();
        $this->actingAs($this->admin);
        foreach ([['with_products' => null], ['with_products' => 'guess'], ['version' => 0], ['request_id' => 'bad'], ['occurred_at' => '2026-01-01 10:00'], ['occurred_at' => now()->addMinute()->toISOString()], ['occurred_at' => now()->subDay()->toISOString()], ['comment' => str_repeat('x', 2001)]] as $invalid) {
            $this->postJson($this->url($plant), [...$this->input($plant), ...$invalid])->assertUnprocessable();
        }
        $this->postJson($this->url($plant), ['version' => 1, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('plant_events', 1);
    }

    public function test_actual_watering_keeps_phase_dates_and_durations_and_alternates_next_choice(): void
    {
        $plant = $this->plant();
        $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.watering.status', 'choose')->assertJsonPath('data.watering.last', null)->assertJsonPath('data.watering.nextWithProducts', null);
        $first = $this->postJson($this->url($plant), [...$this->input($plant), 'comment' => 'Fictional observation'])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.phase', 'sowing')->assertJsonPath('data.watering.last.withProducts', true)->assertJsonPath('data.watering.nextWithProducts', false);
        $fresh = $plant->fresh();
        $this->assertTrue($plant->phase_started_at->equalTo($fresh->phase_started_at));
        $this->assertTrue($plant->phase_due_at->equalTo($fresh->phase_due_at));
        $this->assertSame($plant->duration_snapshot, $fresh->duration_snapshot);
        $this->postJson($this->url($plant), $this->input($fresh, false))->assertOk()->assertJsonPath('data.watering.nextWithProducts', true);
        $this->getJson('/api/v1/plants/'.$plant->id.'/events')->assertOk()->assertJsonPath('data.0.kind', 'watered')->assertJsonPath('data.0.watering.withProducts', false)->assertJsonPath('data.0.watering.duringFlush', false)->assertJsonPath('data.1.comment', 'Fictional observation');
        $this->assertSame(2, PlantEvent::where('kind', 'watered')->count());
    }

    public function test_retries_are_actor_and_payload_bound_and_share_lifecycle_request_namespace(): void
    {
        $plant = $this->plant();
        $input = $this->input($plant);
        $this->actingAs($this->admin)->postJson($this->url($plant), $input)->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson($this->url($plant), [...$input, 'request_id' => strtoupper($input['request_id'])])->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson($this->url($plant), [...$input, 'with_products' => false])->assertConflict();
        $this->postJson($this->url($plant), [...$input, 'comment' => 'Changed'])->assertConflict();
        $other = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($other)->postJson($this->url($plant), $input)->assertConflict();
        $this->actingAs($this->admin)->postJson('/api/v1/plants/'.$plant->id.'/actions', ['version' => 2, 'request_id' => $input['request_id'], 'action' => 'plant'])->assertConflict();
        $this->assertDatabaseCount('plant_events', 2);
    }

    public function test_stale_version_rejects_without_changing_history_and_stale_actor_is_rechecked(): void
    {
        $plant = $this->plant();
        $this->actingAs($this->admin)->patchJson('/api/v1/plants/'.$plant->id, ['version' => 1, 'name' => 'Changed'])->assertOk();
        $this->postJson($this->url($plant), $this->input($plant))->assertConflict();
        $stale = $this->admin->fresh();
        User::whereKey($stale->id)->update(['session_version' => $stale->session_version + 1]);
        try {
            app(PlantTracking::class)->water($stale, $plant->fresh(), $this->input($plant->fresh()));
            $this->fail('Expected stale session rejection.');
        } catch (AuthenticationException) {
            $this->assertSame(0, PlantEvent::where('kind', 'watered')->count());
        }
    }

    public function test_fresh_actor_rejects_demoted_or_inactive_accounts(): void
    {
        $plant = $this->plant();
        $stale = $this->admin->fresh();
        foreach ([['permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id]], ['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id], 'is_active' => false]] as $change) {
            $stale->fresh()->forceFill($change)->save();
            try {
                app(PlantTracking::class)->water($stale, $plant, $this->input($plant));
                $this->fail('Expected fresh actor authorization rejection.');
            } catch (AuthorizationException) {
                $this->assertSame(0, PlantEvent::where('kind', 'watered')->count());
            }
        }
    }

    public function test_default_flush_boundary_is_inclusive_and_actual_choice_is_preserved(): void
    {
        $plant = $this->flowering();
        $start = $plant->phase_started_at;
        $this->travelTo($start->addDays(42)->subSecond());
        $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.watering.status', 'choose')->assertJsonPath('data.watering.flushDays', 14)->assertJsonPath('data.watering.flushStartsAt', $start->addDays(42)->toISOString());
        $this->travelTo($start->addDays(42));
        $this->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.watering.status', 'flush')->assertJsonPath('data.watering.nextWithProducts', false);
        $this->postJson($this->url($plant), $this->input($plant, true))->assertOk()->assertJsonPath('data.watering.last.withProducts', true)->assertJsonPath('data.watering.nextWithProducts', false);
        $this->getJson('/api/v1/plants/'.$plant->id.'/events')->assertOk()->assertJsonPath('data.0.watering.duringFlush', true)->assertJsonPath('data.0.watering.withProducts', true);
        $this->travelTo($start->addDays(60));
        $this->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.watering.status', 'flush');
    }

    public function test_strain_override_and_zero_disable_flush_and_catalog_changes_do_not_rewrite_snapshot(): void
    {
        $this->strain->update(['duration_overrides' => ['flowering' => 21, 'flush' => 7]]);
        $plant = $this->flowering();
        $this->strain->update(['duration_overrides' => ['flush' => 20]]);
        $settings = LifecycleSetting::findOrFail(1);
        $settings->update(['durations' => [...$settings->durations, 'flush' => 1]]);
        $this->travelTo($plant->phase_started_at->addDays(14));
        $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.watering.flushDays', 7)->assertJsonPath('data.watering.status', 'flush');
        $this->strain->update(['duration_overrides' => ['flush' => 0]]);
        $disabled = $this->flowering();
        $this->travelTo($disabled->phase_due_at->addDay());
        $this->getJson('/api/v1/plants/'.$disabled->id)->assertOk()->assertJsonPath('data.watering.status', 'choose')->assertJsonPath('data.watering.flushStartsAt', null);
    }

    public function test_flush_longer_than_phase_is_clamped_to_its_start_and_harvest_ends_watering(): void
    {
        $this->strain->update(['duration_overrides' => ['flowering' => 7, 'flush' => 14]]);
        $plant = $this->flowering();
        $this->actingAs($this->admin)->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.watering.flushStartsAt', $plant->phase_started_at->toISOString())->assertJsonPath('data.watering.status', 'flush');
        $harvested = $this->action($plant, 'harvest');
        $this->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.watering.status', 'unavailable')->assertJsonPath('data.watering.nextWithProducts', null);
        $this->postJson($this->url($plant), $this->input($harvested))->assertConflict();
        foreach (['transfer_to_rack', 'transfer_to_containers'] as $action) {
            $harvested = $this->action($harvested, $action);
        }
        $this->travelTo($harvested->phase_due_at);
        $harvested = $this->action($harvested, 'start_maturation', ['harvest_weight_grams' => '10.50']);
        $harvested = $this->action($harvested->fresh(), 'archive');
        $this->postJson($this->url($harvested), $this->input($harvested))->assertConflict();
    }

    public function test_backdated_entries_do_not_replace_last_actual_time_and_ties_use_version(): void
    {
        $plant = $this->plant();
        $start = $plant->phase_started_at;
        $this->travelTo($start->addDays(2));
        $this->actingAs($this->admin)->postJson($this->url($plant), $this->input($plant, true))->assertOk();
        $this->postJson($this->url($plant), [...$this->input($plant->fresh(), false), 'occurred_at' => $start->addDay()->toISOString()])->assertOk()->assertJsonPath('data.watering.last.withProducts', true)->assertJsonPath('data.watering.nextWithProducts', false);
        $this->postJson($this->url($plant), $this->input($plant->fresh(), false))->assertOk()->assertJsonPath('data.watering.last.withProducts', false)->assertJsonPath('data.watering.nextWithProducts', true);
    }

    public function test_last_watering_and_indication_do_not_reveal_history_outside_captured_location(): void
    {
        $plant = $this->plant();
        $other = Site::create(['name' => 'Fictional Allowed Destination']);
        $this->actingAs($this->admin)->postJson($this->url($plant), $this->input($plant))->assertOk();
        app(PlantTracking::class)->update($this->admin, $plant, ['version' => 2, 'location' => ['type' => 'site', 'id' => $other->id]]);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $other->id]]]);
        $this->actingAs($reader)->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.watering.last', null)->assertJsonPath('data.watering.status', 'choose');
        $this->getJson('/api/v1/sites/'.$other->id.'/plant-dashboard')->assertOk()->assertJsonPath('data.0.watering.last', null)->assertJsonPath('data.0.watering.nextWithProducts', null);
        $this->getJson('/api/v1/plants/'.$plant->id.'/events')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/plants')->assertOk()->assertJsonPath('data.0.watering.last', null);
    }

    public function test_eager_loading_keeps_separate_last_watering_for_each_card(): void
    {
        $one = $this->plant();
        $two = $this->plant();
        $this->actingAs($this->admin)->postJson($this->url($one), $this->input($one, true))->assertOk();
        $this->postJson($this->url($two), $this->input($two, false))->assertOk();
        $cards = collect($this->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard')->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($cards[$one->id]['watering']['nextWithProducts']);
        $this->assertTrue($cards[$two->id]['watering']['nextWithProducts']);
        $this->assertSame(2, $cards->count());
    }
}
