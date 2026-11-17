<?php

namespace Tests\Feature;

use App\Models\LifecycleSetting;
use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class LifecycleDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->travelTo(now()->startOfSecond());
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($this->admin);
    }

    private function definition(): array
    {
        return ['version' => 1, 'durations' => ['custom' => 7, 'flush' => 2], 'stages' => [
            ['id' => 'planned', 'name' => null, 'icon' => 'clock', 'watering' => false, 'maintenance' => false, 'flush' => false, 'actions' => [['id' => 'begin', 'name' => 'Begin', 'icon' => 'seed', 'target' => 'custom', 'requires_harvest_weight' => false]]],
            ['id' => 'custom', 'name' => 'Custom stage', 'icon' => 'flower', 'watering' => true, 'maintenance' => true, 'flush' => true, 'actions' => [
                ['id' => 'care', 'name' => 'Care', 'icon' => 'scissors', 'target' => 'custom', 'requires_harvest_weight' => false],
                ['id' => 'finish', 'name' => 'Finish', 'icon' => 'box', 'target' => 'archived', 'requires_harvest_weight' => true],
            ]],
            ['id' => 'archived', 'name' => null, 'icon' => 'archive', 'watering' => false, 'maintenance' => false, 'flush' => false, 'actions' => []],
        ]];
    }

    private function plant(bool $planned = false, array $overrides = []): Plant
    {
        $site = Site::create(['name' => 'Fictional Workflow Site']);
        $strain = Strain::create(['name' => 'Fictional Workflow Strain', 'duration_overrides' => $overrides]);

        return app(PlantTracking::class)->create($this->admin, ['name' => 'Fictional Workflow Plant', 'planned' => $planned, 'request_id' => (string) Str::uuid(), 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id]]);
    }

    public function test_custom_definition_replaces_defaults_and_respects_versions_and_permissions(): void
    {
        $data = $this->definition();
        $this->patchJson('/api/v1/lifecycle-settings', $data)->assertOk()->assertJsonPath('data.stages.1.name', 'Custom stage')->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/lifecycle-settings', $data)->assertConflict();
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/lifecycle-settings', [...$data, 'version' => 2])->assertForbidden();
        $this->getJson('/api/v1/lifecycle-settings')->assertOk()->assertJsonPath('data.canManage', false)->assertJsonPath('data.stages.1.icon', 'flower');
    }

    public function test_custom_creation_strain_overrides_filtering_and_dashboard_use_the_definition(): void
    {
        $this->patchJson('/api/v1/lifecycle-settings', $this->definition())->assertOk();
        $plant = $this->plant(false, ['custom' => 14]);
        $this->assertSame('custom', $plant->phase);
        $this->assertSame(now()->addDays(14)->toISOString(), $plant->phase_due_at->toISOString());
        $this->getJson('/api/v1/plants?phase=custom')->assertOk()->assertJsonPath('data.0.nextAction', 'finish')->assertJsonPath('data.0.availableActions.0.id', 'care');
        $this->getJson('/api/v1/sites/'.$plant->site_id.'/plant-dashboard?phase=custom&sort=phase_desc')->assertOk()->assertJsonPath('data.0.stageDefinition.icon', 'flower');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.plants.byPhase.custom', 1);
        $this->patchJson('/api/v1/strains/'.$plant->strain_id, ['version' => 1, 'duration_overrides' => ['custom' => 21]])->assertOk()->assertJsonPath('data.durationOverrides.custom', 21);
    }

    public function test_same_stage_action_preserves_dates_supports_idempotency_and_records_historical_metadata(): void
    {
        $this->patchJson('/api/v1/lifecycle-settings', $this->definition())->assertOk();
        $plant = $this->plant();
        $due = $plant->phase_due_at->toISOString();
        $start = $plant->phase_started_at->toISOString();
        $this->travel(1)->days();
        $input = ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'care', 'comment' => 'Fictional observation'];
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', $input)->assertOk()->assertJsonPath('data.phase', 'custom')->assertJsonPath('data.phaseDueAt', $due)->assertJsonPath('data.phaseStartedAt', $start)->assertJsonPath('data.version', 2);
        $data = $this->definition();
        $data['version'] = 2;
        $data['stages'][1]['actions'][0]['name'] = 'Changed care';
        $this->patchJson('/api/v1/lifecycle-settings', $data)->assertOk();
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', $input)->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/plants/'.$plant->id.'/events')->assertOk()->assertJsonPath('data.0.snapshot.lifecycle_action.name', 'Care');
        $this->assertDatabaseCount('plant_events', 2);
    }

    public function test_custom_transition_requires_weight_and_waiting_starts_the_configured_first_stage(): void
    {
        $this->patchJson('/api/v1/lifecycle-settings', $this->definition())->assertOk();
        $plant = $this->plant(true);
        $input = ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'begin'];
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', $input)->assertOk()->assertJsonPath('data.phase', 'custom')->assertJsonPath('data.startedAt', now()->toISOString());
        $finish = ['version' => 2, 'request_id' => (string) Str::uuid(), 'action' => 'finish'];
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', $finish)->assertUnprocessable();
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', [...$finish, 'harvest_weight_grams' => '12.50'])->assertOk()->assertJsonPath('data.phase', 'archived')->assertJsonPath('data.harvestWeightGrams', '12.50');
        // Replays still succeed after the action is removed from the current definition.
        $data = $this->definition();
        $data['version'] = 2;
        $data['stages'][1]['actions'][1]['id'] = 'new_finish';
        $this->patchJson('/api/v1/lifecycle-settings', $data)->assertOk();
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', [...$finish, 'harvest_weight_grams' => '12.50'])->assertOk()->assertJsonPath('data.version', 3);
    }

    public function test_in_use_stage_removal_is_rejected_without_touching_plants_or_settings(): void
    {
        $plant = $this->plant();
        $this->patchJson('/api/v1/lifecycle-settings', $this->definition())->assertUnprocessable()->assertJsonPath('errors.stages.0', 'catalog.validation.stageInUse');
        $this->assertSame('sowing', $plant->fresh()->phase);
        $this->assertSame(1, LifecycleSetting::findOrFail(1)->version);
    }

    public function test_invalid_graph_identifiers_icons_flags_and_duration_keys_are_rejected(): void
    {
        $changes = [
            ['stages.1.id', "x' THEN 0 END"], ['stages.1.actions.0.id', 'watered'], ['stages.1.icon', 'https://example.test/icon.svg'],
            ['stages.1.actions.0.target', 'missing'], ['stages.1.actions.1.target', 'custom'], ['stages.1.actions.1.id', 'care'],
            ['stages.1.watering', false], ['stages.0.watering', true], ['stages.0.id', 'other'], ['durations.custom', 1.5], ['durations.unknown', 2],
        ];
        foreach ($changes as [$path, $value]) {
            $data = $this->definition();
            data_set($data, $path, $value);
            $this->patchJson('/api/v1/lifecycle-settings', $data)->assertUnprocessable();
        }
        $this->assertSame(1, LifecycleSetting::findOrFail(1)->version);
    }

    public function test_actions_can_skip_stages_and_return_without_losing_the_original_start(): void
    {
        $data = $this->definition();
        $preparation = ['id' => 'preparation', 'name' => 'Preparation', 'icon' => 'pot', 'watering' => false, 'maintenance' => false, 'flush' => false,
            'actions' => [['id' => 'retry', 'name' => 'Retry', 'icon' => 'arrow', 'target' => 'custom', 'requires_harvest_weight' => false]]];
        array_splice($data['stages'], 1, 0, [$preparation]);
        $data['durations']['preparation'] = 5;
        $data['stages'][0]['actions'][] = ['id' => 'observe', 'name' => 'Observe', 'icon' => 'camera', 'target' => 'planned', 'requires_harvest_weight' => false];
        $data['stages'][2]['actions'][] = ['id' => 'return', 'name' => 'Return', 'icon' => 'pot', 'target' => 'preparation', 'requires_harvest_weight' => false];
        $data['stages'][2]['actions'][] = ['id' => 'wait', 'name' => 'Wait', 'icon' => 'clock', 'target' => 'planned', 'requires_harvest_weight' => false];
        $this->patchJson('/api/v1/lifecycle-settings', $data)->assertOk();
        $plant = $this->plant(true);
        $perform = function (string $action) use ($plant) {
            return $this->postJson('/api/v1/plants/'.$plant->id.'/actions', ['action' => $action, 'version' => $plant->fresh()->version, 'request_id' => (string) Str::uuid()]);
        };
        $perform('observe')->assertOk()->assertJsonPath('data.phase', 'planned')->assertJsonPath('data.startedAt', null)->assertJsonPath('data.phaseStartedAt', null);
        $perform('begin')->assertOk()->assertJsonPath('data.phase', 'custom');
        $originalStart = $plant->fresh()->started_at->toISOString();
        $this->travel(2)->days();
        $perform('return')->assertOk()->assertJsonPath('data.phase', 'preparation')
            ->assertJsonPath('data.phaseStartedAt', now()->toISOString())->assertJsonPath('data.phaseDueAt', now()->addDays(5)->toISOString());
        $this->getJson('/api/v1/plants/'.$plant->id.'/events')->assertOk()->assertJsonPath('data.0.fromPhase', 'custom')->assertJsonPath('data.0.toPhase', 'preparation');
        $perform('retry')->assertOk()->assertJsonPath('data.phase', 'custom');
        $perform('wait')->assertOk()->assertJsonPath('data.phase', 'planned')->assertJsonPath('data.phaseDueAt', null);
        $this->travel(1)->days();
        $perform('begin')->assertOk()->assertJsonPath('data.phase', 'custom')->assertJsonPath('data.startedAt', $originalStart);
        $this->assertDatabaseCount('plant_events', 7);
    }

    public function test_plants_can_choose_different_branches_from_the_same_stage(): void
    {
        $data = $this->definition();
        foreach (['path_a' => 3, 'path_b' => 10] as $id => $days) {
            $data['durations'][$id] = $days;
            $data['stages'][1]['actions'][] = ['id' => 'choose_'.$id, 'name' => 'Choose '.$id, 'icon' => 'arrow', 'target' => $id, 'requires_harvest_weight' => false];
            array_splice($data['stages'], -1, 0, [[
                'id' => $id, 'name' => $id, 'icon' => 'leaf', 'watering' => false, 'maintenance' => false, 'flush' => false,
                'actions' => [['id' => 'finish_'.$id, 'name' => 'Finish '.$id, 'icon' => 'archive', 'target' => 'archived', 'requires_harvest_weight' => false]],
            ]]);
        }
        $this->patchJson('/api/v1/lifecycle-settings', $data)->assertOk();
        $first = $this->plant();
        $branchPlants = ['path_a' => $first, 'path_b' => app(PlantTracking::class)->create($this->admin, [
            'name' => 'Fictional Second Branch Plant', 'request_id' => (string) Str::uuid(), 'strain_id' => $first->strain_id,
            'location' => ['type' => 'site', 'id' => $first->site_id],
        ])];
        foreach (['path_a' => 3, 'path_b' => 10] as $id => $days) {
            $plant = $branchPlants[$id];
            $this->postJson('/api/v1/plants/'.$plant->id.'/actions', ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'choose_'.$id])
                ->assertOk()->assertJsonPath('data.phase', $id)->assertJsonPath('data.phaseDueAt', now()->addDays($days)->toISOString());
            $other = $id === 'path_a' ? 'path_b' : 'path_a';
            $this->postJson('/api/v1/plants/'.$plant->id.'/actions', ['version' => 2, 'request_id' => (string) Str::uuid(), 'action' => 'finish_'.$other])->assertConflict();
            $this->postJson('/api/v1/plants/'.$plant->id.'/actions', ['version' => 2, 'request_id' => (string) Str::uuid(), 'action' => 'finish_'.$id])
                ->assertOk()->assertJsonPath('data.phase', 'archived');
        }
    }

    public function test_backward_loops_without_a_route_to_archival_are_rejected(): void
    {
        $data = $this->definition();
        $data['stages'][1]['actions'][1]['target'] = 'planned';
        $this->patchJson('/api/v1/lifecycle-settings', $data)->assertUnprocessable();
        $this->assertSame(1, LifecycleSetting::findOrFail(1)->version);
    }

    public function test_global_duration_changes_preserve_existing_schedule_and_snapshot(): void
    {
        $plant = $this->plant();
        $snapshot = $plant->duration_snapshot;
        $due = $plant->phase_due_at->toISOString();
        $settings = LifecycleSetting::findOrFail(1);
        $durations = $settings->durations;
        $durations['sowing'] = 14;
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => 1, 'durations' => $durations, 'stages' => $settings->stages])->assertOk();
        $this->assertSame($snapshot, $plant->fresh()->duration_snapshot);
        $this->assertSame($due, $plant->fresh()->phase_due_at->toISOString());
    }

    public function test_added_stage_resolves_and_retains_strain_duration_when_an_existing_plant_enters_it(): void
    {
        $this->patchJson('/api/v1/lifecycle-settings', $this->definition())->assertOk();
        $plant = $this->plant();
        $data = $this->definition();
        $data['version'] = 2;
        $data['durations']['preparation'] = 5;
        $data['stages'][1]['actions'][1]['target'] = 'preparation';
        $data['stages'][1]['actions'][1]['requires_harvest_weight'] = false;
        array_splice($data['stages'], 2, 0, [[
            'id' => 'preparation', 'name' => 'Preparation', 'icon' => 'jar', 'watering' => false, 'maintenance' => false, 'flush' => false,
            'actions' => [['id' => 'store', 'name' => 'Store', 'icon' => 'archive', 'target' => 'archived', 'requires_harvest_weight' => false]],
        ]]);
        $this->patchJson('/api/v1/lifecycle-settings', $data)->assertOk();
        $this->patchJson('/api/v1/strains/'.$plant->strain_id, ['version' => 1, 'duration_overrides' => ['preparation' => 12]])->assertOk();
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'finish'])
            ->assertOk()->assertJsonPath('data.phase', 'preparation')->assertJsonPath('data.phaseDueAt', now()->addDays(12)->toISOString())
            ->assertJsonPath('data.durationSnapshot.durations.preparation', 12)->assertJsonPath('data.watering.status', 'unavailable');
    }
}
