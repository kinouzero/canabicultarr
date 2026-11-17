<?php

namespace Tests\Feature;

use App\Models\LifecycleSetting;
use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Models\Zone;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LifecycleRelocationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $source;

    private array $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($this->admin);
        $this->source = Site::create(['name' => 'Source site']);
        $site = Site::create(['name' => 'Destination site']);
        $zone = $site->rooms()->create(['name' => 'Destination room'])->zones()->create(['name' => 'Destination zone']);
        $this->destination = ['type' => 'zone', 'id' => $zone->id];
    }

    private function configure(bool $enabled = true, string $phase = 'sowing'): void
    {
        $settings = LifecycleSetting::findOrFail(1);
        $stages = $settings->stages;
        foreach ($stages as &$stage) {
            if ($stage['id'] === $phase) {
                $stage['target_location'] = $this->destination;
            }
        }
        unset($stage);
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => $settings->version, 'durations' => $settings->durations, 'stages' => $stages, 'automatic_relocation' => $enabled])->assertOk()->assertJsonPath('data.automaticRelocation', $enabled);
    }

    private function plant(bool $planned = true): Plant
    {
        $strain = Strain::firstOrCreate(['name' => 'Relocation strain']);

        return app(PlantTracking::class)->create($this->admin, ['name' => 'Relocation plant', 'planned' => $planned, 'request_id' => (string) Str::uuid(), 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $this->source->id]]);
    }

    public function test_disabled_and_unconfigured_stages_retain_manual_locations_and_creation_can_enter_a_target(): void
    {
        $this->getJson('/api/v1/lifecycle-settings')->assertOk()->assertJsonPath('data.automaticRelocation', false);
        $this->configure(false);
        $manual = $this->plant(false);
        $this->assertSame($this->source->id, $manual->site_id);
        $this->configure();
        $auto = $this->plant(false);
        $this->assertSame($this->destination['id'], $auto->zone_id);
        $this->assertNull($auto->site_id);
        $this->assertSame($this->source->id, $auto->events()->first()->snapshot['previous_location']['id']);
        $this->postJson('/api/v1/plants/'.$auto->id.'/actions', ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'plant'])
            ->assertOk()->assertJsonPath('data.phase', 'germination')->assertJsonPath('data.location.id', $this->destination['id']);
    }

    public function test_transitions_move_atomically_record_prior_context_and_replays_never_move_twice(): void
    {
        $this->configure();
        $plant = $this->plant();
        $input = ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'sow'];
        $url = '/api/v1/plants/'.$plant->id.'/actions';
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.location.id', $this->destination['id'])->assertJsonPath('data.location.type', 'zone')->assertJsonPath('data.version', 2);
        $event = $plant->events()->where('request_id', $input['request_id'])->firstOrFail();
        $this->assertTrue($event->snapshot['automatic_relocation']);
        $this->assertSame($this->source->id, $event->snapshot['previous_location']['id']);
        $this->assertSame($this->destination['id'], $event->snapshot['location']['id']);
        $this->assertSame(['location'], $event->fields);
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame(2, $plant->events()->count());
        $this->getJson('/api/v1/sites/'.$this->source->id.'/plant-dashboard')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.location.zone.id', $this->destination['id']);
    }

    public function test_deleted_destination_rejects_the_entire_action_and_can_be_disabled_without_repairing_stale_targets(): void
    {
        $this->configure();
        $plant = $this->plant();
        Zone::findOrFail($this->destination['id'])->delete();
        $input = ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'sow'];
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', $input)->assertUnprocessable()->assertJsonPath('error.code', 'plants.errors.relocationUnavailable');
        $this->assertSame('planned', $plant->fresh()->phase);
        $this->assertSame(1, $plant->fresh()->version);
        $this->assertSame($this->source->id, $plant->fresh()->site_id);
        $this->assertSame(1, $plant->events()->count());
        $this->configure(false);
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', $input)->assertOk()->assertJsonPath('data.phase', 'sowing')->assertJsonPath('data.location.id', $this->source->id);
        $settings = LifecycleSetting::findOrFail(1);
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => $settings->version, 'durations' => $settings->durations, 'stages' => $settings->stages, 'automatic_relocation' => true])->assertUnprocessable()->assertJsonValidationErrors('stages.1.target_location');
    }

    public function test_same_stage_actions_do_not_reapply_destinations(): void
    {
        $this->configure();
        $plant = $this->plant(false);
        $settings = LifecycleSetting::findOrFail(1);
        $stages = $settings->stages;
        $stages[1]['target_location'] = ['type' => 'site', 'id' => $this->source->id];
        $stages[1]['actions'][] = ['id' => 'inspect_sowing', 'name' => 'Inspect', 'icon' => 'camera', 'target' => 'sowing', 'requires_harvest_weight' => false];
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => $settings->version, 'durations' => $settings->durations, 'stages' => $stages, 'automatic_relocation' => true])->assertOk();
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'inspect_sowing'])
            ->assertOk()->assertJsonPath('data.location.id', $this->destination['id'])->assertJsonPath('data.phaseDueAt', $plant->phase_due_at->toISOString());
        $this->assertFalse($plant->events()->where('kind', 'inspect_sowing')->firstOrFail()->snapshot['automatic_relocation']);
    }

    public function test_configuration_requires_administration_and_entry_requires_destination_access(): void
    {
        $this->configure();
        $plant = $this->plant();
        $member = User::factory()->create(['permission_grants' => ['plants.manage', 'lifecycle.manage'], 'location_access' => [['type' => 'site', 'id' => $this->source->id]]]);
        $this->actingAs($member);
        $settings = LifecycleSetting::findOrFail(1);
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => $settings->version, 'durations' => $settings->durations, 'stages' => $settings->stages, 'automatic_relocation' => true])
            ->assertForbidden();
        $this->postJson('/api/v1/plants/'.$plant->id.'/actions', ['version' => 1, 'request_id' => (string) Str::uuid(), 'action' => 'sow'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'plants.errors.relocationUnavailable');
        $this->assertSame('planned', $plant->fresh()->phase);
        $this->assertSame(1, $plant->events()->count());
        $member->update(['permission_grants' => []]);
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => $settings->version, 'durations' => $settings->durations, 'automatic_relocation' => false])->assertForbidden();
    }
}
