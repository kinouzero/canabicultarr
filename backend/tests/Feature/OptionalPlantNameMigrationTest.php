<?php

namespace Tests\Feature;

use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionalPlantNameMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_populated_nullable_name_migration_roundtrip_preserves_records_and_events(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($admin);
        $site = Site::create(['name' => 'Migration site']);
        $strain = Strain::create(['name' => 'Migration strain']);
        $plants = collect([null, 'Preserved name'])->map(fn ($name) => app(PlantTracking::class)->create($admin, ['name' => $name, 'strain_id' => $strain->id, 'planned' => false, 'location' => ['type' => 'site', 'id' => $site->id], 'request_id' => (string) Str::uuid()]));
        $events = DB::table('plant_events')->orderBy('id')->get()->toJson();
        $this->reapplySchemaFrom('2026_11_14_190000_allow_unnamed_plants.php');
        $this->assertNull(Plant::findOrFail($plants[0]->id)->name);
        $this->assertSame('Preserved name', Plant::findOrFail($plants[1]->id)->name);
        $this->assertSame($site->id, $plants[0]->fresh()->site_id);
        $this->assertSame($strain->id, $plants[0]->fresh()->strain_id);
        $this->assertSame($events, DB::table('plant_events')->orderBy('id')->get()->toJson());
    }
}
