<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\DocumentLibrary;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StrainNamingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_populated_rename_round_trip_preserves_ids_links_metadata_history_and_constraints(): void
    {
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
        $actor = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($actor);
        $site = Site::create(['name' => 'Migration site']);
        $stock = InventoryItem::create(['name' => 'Migration seeds', 'type' => 'seeds', 'unit' => 'piece']);
        $strain = Strain::create(['name' => 'Migration strain', 'notes' => 'Keep variety and variété in user notes.']);
        $plant = app(PlantTracking::class)->create($actor, ['name' => 'Migration plant', 'notes' => 'User variety notes', 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id], 'request_id' => (string) Str::uuid()]);
        $strain->update(['seed_inventory_item_id' => $stock->id]);
        $snapshot = $plant->events()->firstOrFail();
        $snapshot->update(['fields' => ['strain_id'], 'comment' => 'An unchanged variety comment']);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+c+XcAAAAASUVORK5CYII=');
        $document = app(DocumentLibrary::class)->upload($actor, ['title' => 'Migration image', 'subject' => ['type' => 'strain', 'id' => $strain->id]], UploadedFile::fake()->createWithContent('photo.png', $bytes));
        $strain->photos()->attach($document->id);
        $snapshot->documents()->attach($document->id);
        $before = ['plant' => $plant->fresh()->getRawOriginal(), 'strain' => $strain->fresh()->getRawOriginal(), 'event' => $snapshot->getRawOriginal(), 'document' => $document->getRawOriginal()];
        $migration = require database_path('migrations/2026_11_14_180000_rename_varieties_to_strains.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('strains'));
        $this->assertTrue(Schema::hasColumn('plants', 'variety_id'));
        $this->assertSame($strain->id, DB::table('plants')->where('id', $plant->id)->value('variety_id'));
        $this->assertSame($stock->id, DB::table('varieties')->where('id', $strain->id)->value('seed_inventory_item_id'));
        $this->assertSame('variety', DB::table('documents')->where('id', $document->id)->value('subject_kind'));
        $this->assertSame($strain->id, json_decode(DB::table('plant_events')->where('id', $snapshot->id)->value('snapshot'), true)['variety_id']);
        $this->assertDatabaseHas('variety_photos', ['variety_id' => $strain->id, 'document_id' => $document->id]);
        $migration->up();
        $this->assertFalse(Schema::hasTable('varieties'));
        $this->assertFalse(Schema::hasColumn('plants', 'variety_id'));
        $this->assertEquals($before['plant'], $plant->fresh()->getRawOriginal());
        $this->assertEquals($before['strain'], $strain->fresh()->getRawOriginal());
        $after = $snapshot->fresh();
        $this->assertEquals(json_decode($before['event']['snapshot'], true), $after->snapshot);
        $this->assertSame('An unchanged variety comment', $after->comment);
        $this->assertSame($before['event']['recorded_at'], $after->getRawOriginal('recorded_at'));
        $this->assertSame($before['event']['request_hash'], $after->request_hash);
        $this->assertSame(['strain_id'], $after->fields);
        $this->assertDatabaseHas('strain_photos', ['strain_id' => $strain->id, 'document_id' => $document->id]);
        $this->assertDatabaseHas('plant_event_documents', ['plant_event_id' => $snapshot->id, 'document_id' => $document->id]);
        $afterDocument = Document::findOrFail($document->id);
        $this->assertSame('strain', $afterDocument->subject_kind);
        $this->assertSame($before['document']['path'], $afterDocument->path);
        $this->getJson('/api/v1/strains/'.$strain->id)->assertOk()->assertJsonPath('data.seedStock.id', $stock->id);
        $this->getJson('/api/v1/plants/'.$plant->id)->assertOk()->assertJsonPath('data.strain.id', $strain->id);
        $this->getJson('/api/v1/varieties')->assertNotFound();
        $foreign = collect(Schema::getForeignKeys('plants'))->first(fn ($key) => $key['columns'] === ['strain_id']);
        $this->assertSame('strains', $foreign['foreign_table']);
        $this->assertNotEmpty(Schema::getForeignKeys('strain_photos'));
    }
}
