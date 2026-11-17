<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\DocumentLibrary;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecordDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function records(): array
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Deletion site']);
        $strain = Strain::create(['name' => 'Deletion strain']);
        $plant = app(PlantTracking::class)->create($admin, ['name' => 'Deletion plant', 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id], 'request_id' => (string) Str::uuid()]);

        return [$admin, $site, $strain, $plant];
    }

    public function test_plant_delete_checks_access_and_version_and_preserves_shared_resources(): void
    {
        [$admin, $site, $strain, $plant] = $this->records();
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
        $document = app(DocumentLibrary::class)->upload($admin, ['title' => 'Retained file', 'subject' => ['type' => 'site', 'id' => $site->id]], UploadedFile::fake()->createWithContent('retained.txt', 'Retained attachment'));
        $plant->events()->first()->documents()->attach($document->id);
        $device = Asset::create(['name' => 'Retained device', 'site_id' => $site->id]);
        $plant->devices()->attach($device->id);
        $url = '/api/v1/plants/'.$plant->id;
        $this->deleteJson($url, ['version' => 1])->assertUnauthorized();
        $reader = User::factory()->create();
        $this->actingAs($reader)->deleteJson($url, ['version' => 1])->assertForbidden();
        $outside = User::factory()->create(['permission_grants' => ['plants.manage'], 'location_access' => []]);
        $this->actingAs($outside)->deleteJson($url, ['version' => 1])->assertNotFound();
        $this->actingAs($admin)->deleteJson($url, [])->assertUnprocessable();
        $this->deleteJson($url, ['version' => 2])->assertConflict();
        $this->assertDatabaseHas('plants', ['id' => $plant->id]);
        $plant->forceFill(['phase' => 'archived'])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.canDelete', true)->assertJsonPath('data.canManage', false);
        $this->deleteJson($url, ['version' => 1])->assertNoContent();
        $this->assertDatabaseMissing('plants', ['id' => $plant->id]);
        $this->assertSame(0, DB::table('plant_events')->where('plant_id', $plant->id)->count());
        $this->assertDatabaseCount('plant_event_documents', 0);
        $this->assertDatabaseCount('plant_device', 0);
        $this->assertDatabaseHas('assets', ['id' => $device->id]);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        Storage::disk('documents')->assertExists($document->path);
        $this->assertDatabaseHas('strains', ['id' => $strain->id]);
        $this->deleteJson($url, ['version' => 1])->assertNotFound();
    }

    public function test_strain_delete_cannot_bypass_hidden_or_archived_plant_dependencies(): void
    {
        [$admin, $site, $strain, $plant] = $this->records();
        $plant->forceFill(['phase' => 'archived'])->save();
        $manager = User::factory()->create(['permission_grants' => ['strains.manage'], 'location_access' => []]);
        $url = '/api/v1/strains/'.$strain->id;
        $this->actingAs($manager)->deleteJson($url, ['version' => 1])->assertConflict()->assertJsonPath('error.code', 'catalog.errors.inUse');
        $this->assertDatabaseHas('strains', ['id' => $strain->id]);
        $this->actingAs($admin);
        app(PlantTracking::class)->delete($admin, $plant, 1);
        $this->actingAs($manager);
        $this->deleteJson($url, ['version' => 2])->assertConflict();
        $this->deleteJson($url, ['version' => 1])->assertNoContent();
        $this->assertDatabaseMissing('strains', ['id' => $strain->id]);
        $reader = User::factory()->create();
        $other = Strain::create(['name' => 'Protected strain']);
        $this->actingAs($reader)->deleteJson('/api/v1/strains/'.$other->id, ['version' => 1])->assertForbidden();
    }
}
