<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use App\Services\DocumentLibrary;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocationPhotosTest extends TestCase
{
    use RefreshDatabase;

    private function photo(User $actor, Site $site): Document
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+c+XcAAAAASUVORK5CYII=');

        return app(DocumentLibrary::class)->upload($actor, ['title' => 'Location photo', 'subject' => ['type' => 'site', 'id' => $site->id]], UploadedFile::fake()->createWithContent('photo.png', $image));
    }

    public function test_each_location_has_an_independent_private_gallery_and_cover_without_overwriting_metadata(): void
    {
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Photo site', 'description' => 'Keep notes', 'watering_alert_days' => 5]);
        $room = new Room(['name' => 'Photo room', 'description' => 'Keep notes', 'watering_alert_days' => 5]);
        $room->site()->associate($site);
        $room->save();
        $zone = new Zone(['name' => 'Photo zone', 'description' => 'Keep notes', 'watering_alert_days' => 5]);
        $zone->room()->associate($room);
        $zone->save();
        $photos = [];
        foreach ([$site, $room, $zone] as $record) {
            $url = '/api/v1/'.match ($record->kind()) {
                'site' => 'sites/'.$site->id, 'room' => 'sites/'.$site->id.'/rooms/'.$room->id, 'zone' => 'sites/'.$site->id.'/rooms/'.$room->id.'/zones/'.$zone->id
            };
            $gallery = '/api/v1/locations/'.$record->kind().'/'.$record->id.'/photos';
            $this->getJson($gallery)->assertUnauthorized();
            $photo = $this->photo($admin, $site);
            $photos[] = $photo;
            $this->actingAs($admin)->patchJson($url, ['photo_document_id' => $photo->id])->assertOk()->assertJsonPath('data.hasPhoto', true)->assertJsonPath('data.description', 'Keep notes')->assertJsonPath('data.wateringAlertDays', 5);
            $this->getJson($gallery)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $photo->id)->assertJsonPath('data.0.isCover', true);
            $second = $this->photo($admin, $site);
            $this->patchJson($url, ['photo_document_id' => $second->id])->assertOk();
            $this->getJson($gallery)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $second->id);
            $this->patchJson($url, ['photo_document_id' => null])->assertOk()->assertJsonPath('data.hasPhoto', false)->assertJsonPath('data.photoUrl', null);
            $this->getJson($gallery)->assertOk()->assertJsonCount(2, 'data');
            $second->update(['is_archived' => true]);
            $this->getJson($gallery)->assertOk()->assertJsonCount(1, 'data');
            Storage::disk('documents')->assertExists($photo->path);
            $this->getJson($gallery.'?page=0')->assertUnprocessable();
            auth()->forgetGuards();
        }
        $reader = User::factory()->create();
        $this->actingAs($reader)->patchJson('/api/v1/sites/'.$site->id, ['photo_document_id' => $photos[0]->id])->assertForbidden();
        $this->getJson('/api/v1/locations/site/'.$site->id.'/photos')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_gallery_rollback_and_reapply_preserve_location_hierarchy_documents_and_foreign_keys(): void
    {
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Preserved site', 'description' => 'Site notes']);
        $room = $site->rooms()->create(['name' => 'Preserved room']);
        $zone = $room->zones()->create(['name' => 'Preserved zone']);
        $photos = [];
        foreach ([$site, $room, $zone] as $record) {
            $photo = $this->photo($admin, $site);
            $record->photos()->attach($photo->id);
            $record->forceFill(['photo_document_id' => $photo->id])->save();
            $this->assertSame($photo->id, $record->fresh()->photo_document_id);
            $photos[] = $photo;
        }
        $tables = ['users', 'sites', 'rooms', 'zones', 'documents'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => collect((array) $row)->except('photo_document_id')->all())->all();
        }
        $migration = require database_path('migrations/2026_11_10_000100_add_location_photo_galleries.php');
        $migration->down();
        foreach (['sites', 'rooms', 'zones'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'photo_document_id'));
        }
        foreach (['site_photos', 'room_photos', 'zone_photos'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $migration->up();
        foreach ($tables as $table) {
            $after = DB::table($table)->orderBy('id')->get()->map(fn ($row) => collect((array) $row)->except('photo_document_id')->all())->all();
            $this->assertSame($before[$table], $after, $table);
        }
        foreach ([$site, $room, $zone] as $index => $record) {
            $record->refresh();
            $this->assertNull($record->photo_document_id);
            $this->assertSame(0, $record->photos()->count());
            $record->photos()->attach($photos[$index]->id);
            $record->forceFill(['photo_document_id' => $photos[$index]->id])->save();
            $this->assertSame($photos[$index]->id, $record->fresh()->photo_document_id);
            Storage::disk('documents')->assertExists($photos[$index]->path);
            // Reapplied cover references still clear on document deletion.
            $photos[$index]->delete();
            $this->assertNull($record->fresh()->photo_document_id);
            $this->assertSame(0, $record->photos()->count());
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(1, (int) DB::scalar('pragma foreign_keys'));
            $this->assertSame([], DB::select('pragma foreign_key_check'));
        }
    }

    public function test_location_photos_reject_inaccessible_archived_and_non_image_documents_and_hide_navigation_only_ancestors(): void
    {
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Permitted site']);
        $room = new Room(['name' => 'Scoped room']);
        $room->site()->associate($site);
        $room->save();
        $other = Site::create(['name' => 'Hidden site']);
        $private = $this->photo($admin, $other);
        $photo = $this->photo($admin, $site);
        $note = app(DocumentLibrary::class)->upload($admin, ['title' => 'Note', 'subject' => ['type' => 'site', 'id' => $site->id]], UploadedFile::fake()->createWithContent('note.txt', 'Preserved note'));
        $manager = User::factory()->create(['permission_grants' => ['locations.manage'], 'location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $url = '/api/v1/sites/'.$site->id;
        $this->actingAs($manager)->patchJson($url, ['photo_document_id' => $private->id])->assertUnprocessable()->assertJsonPath('error.code', 'locations.errors.photoUnavailable');
        $this->patchJson($url, ['photo_document_id' => $note->id])->assertUnprocessable();
        $this->patchJson($url, ['photo_document_id' => 'invalid'])->assertUnprocessable();
        $this->patchJson($url, ['photo_document_id' => $photo->id])->assertOk();
        $photo->update(['is_archived' => true]);
        $this->patchJson($url, ['photo_document_id' => $photo->id])->assertUnprocessable();
        $this->getJson('/api/v1/sites')->assertOk()->assertJsonPath('data.0.photoUrl', null);
        $photo->update(['is_archived' => false]);
        $roomReader = User::factory()->create(['location_access' => [['type' => 'room', 'id' => $room->id]]]);
        $this->actingAs($roomReader)->getJson('/api/v1/sites')->assertOk()->assertJsonPath('data.0.canSelect', false)->assertJsonPath('data.0.photoUrl', null);
        $this->getJson('/api/v1/locations/site/'.$site->id.'/photos')->assertNotFound();
        $this->getJson('/api/v1/locations/site/'.$other->id.'/photos')->assertNotFound();
        $this->getJson('/api/v1/locations/not-a-kind/'.$site->id.'/photos')->assertNotFound();
    }
}
