<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionalPlantNameTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Strain $strain;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $this->site = Site::create(['name' => 'Optional name site']);
        $this->strain = Strain::create(['name' => 'Fictional strain']);
    }

    private function input(array $extra = []): array
    {
        return ['request_id' => (string) Str::uuid(), 'strain_id' => $this->strain->id, 'location' => ['type' => 'site', 'id' => $this->site->id], 'planned' => true, ...$extra];
    }

    public function test_absent_null_and_blank_names_share_the_fallback_and_creation_replay(): void
    {
        $input = $this->input();
        $response = $this->postJson('/api/v1/plants', $input)->assertCreated()->assertJsonPath('data.givenName', null);
        $id = $response->json('data.id');
        $label = $this->strain->name.' · #'.substr($id, 0, 8);
        $response->assertJsonPath('data.name', $label);
        foreach ([null, '', '   ', "\t\u{00A0}"] as $name) {
            $this->postJson('/api/v1/plants', [...$input, 'name' => $name])->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.name', $label);
        }
        $this->assertNull(Plant::findOrFail($id)->name);
        $this->assertDatabaseCount('plants', 1);
        $this->assertDatabaseCount('plant_events', 1);
        $this->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard?status=planned')->assertOk()->assertJsonPath('data.0.name', $label);
        $this->getJson('/api/v1/plants/'.$id.'/events')->assertOk()->assertJsonPath('data.0.snapshot.name', $label);
    }

    public function test_clearing_a_name_preserves_identity_schedule_and_omitted_name_is_a_noop(): void
    {
        $id = $this->postJson('/api/v1/plants', $this->input(['name' => 'Named plant']))->assertCreated()->json('data.id');
        $plant = Plant::findOrFail($id);
        $this->patchJson('/api/v1/plants/'.$id, ['version' => 1])->assertOk()->assertJsonPath('data.givenName', 'Named plant')->assertJsonPath('data.version', 1);
        $this->patchJson('/api/v1/plants/'.$id, ['version' => 1, 'name' => ' '])->assertOk()->assertJsonPath('data.givenName', null)->assertJsonPath('data.id', $id)->assertJsonPath('data.durationSnapshot', $plant->duration_snapshot)->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/plants/'.$id, ['version' => 2, 'name' => null])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/plants/'.$id, ['version' => 1, 'name' => 'Stale'])->assertConflict();
        foreach ([['bad'], str_repeat('x', 121)] as $name) {
            $this->patchJson('/api/v1/plants/'.$id, ['version' => 2, 'name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->patchJson('/api/v1/plants/'.$id, ['version' => 2, 'name' => '0'])->assertOk()->assertJsonPath('data.name', '0')->assertJsonPath('data.givenName', '0');
        $this->assertDatabaseCount('plant_events', 3);
    }

    public function test_search_finds_the_strain_short_reference_and_complete_fallback_literally(): void
    {
        $id = $this->postJson('/api/v1/plants', $this->input())->assertCreated()->json('data.id');
        $label = $this->strain->name.' · #'.substr($id, 0, 8);
        foreach ([$this->strain->name, substr($id, 0, 8), '#'.substr($id, 0, 8), $label] as $search) {
            $this->getJson('/api/v1/plants?status=planned&search='.rawurlencode($search))->assertOk()->assertJsonPath('data.0.id', $id);
        }
        $this->getJson('/api/v1/plants?status=planned&search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hardware_assignment_labels_include_the_fallback_for_unnamed_plants(): void
    {
        $device = Asset::create(['name' => 'Linked hardware', 'site_id' => $this->site->id]);
        $plant = $this->postJson('/api/v1/plants', $this->input(['device_ids' => [$device->id]]))->assertCreated()->json('data');
        $this->getJson('/api/v1/assets')->assertOk()->assertJsonPath('data.0.plants.0.name', $plant['name']);
    }
}
