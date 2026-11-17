<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\DocumentLibrary;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecordPhotoGalleryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private Strain $strain;

    private Plant $plant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
        $this->travelTo(now()->startOfSecond());
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->site = Site::create(['name' => 'Fictional gallery site']);
        $this->strain = Strain::create(['name' => 'Fictional gallery strain']);
        $this->plant = $this->makePlant($this->site);
    }

    private function makePlant(Site $site): Plant
    {
        return app(PlantTracking::class)->create($this->admin, ['name' => 'Fictional gallery plant', 'strain_id' => $this->strain->id, 'location' => ['type' => 'site', 'id' => $site->id], 'request_id' => (string) Str::uuid()]);
    }

    private function photo(?Site $site = null): Document
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+c+XcAAAAASUVORK5CYII=');

        return app(DocumentLibrary::class)->upload($this->admin, ['title' => 'Fictional gallery image', 'subject' => ['type' => 'site', 'id' => ($site ?? $this->site)->id]], UploadedFile::fake()->createWithContent('photo.png', $image));
    }

    public function test_strain_correction_replaces_inherited_photos_and_retains_own_photos(): void
    {
        $oldPhoto = $this->photo();
        $newPhoto = $this->photo();
        $ownPhoto = $this->photo();
        $this->strain->photos()->attach($oldPhoto->id);
        $replacement = Strain::create(['name' => 'Replacement gallery strain']);
        $replacement->photos()->attach($newPhoto->id);
        $this->plant->photos()->attach($ownPhoto->id);
        $this->actingAs($this->admin)->patchJson('/api/v1/plants/'.$this->plant->id, ['version' => 1, 'strain_id' => $replacement->id, 'strain_change_reason' => 'Corrected identification'])->assertOk()
            ->assertJsonPath('data.photoUrl', '/api/v1/documents/'.$newPhoto->id.'/preview?version='.$newPhoto->version);
        $photos = $this->getJson('/api/v1/plants/'.$this->plant->id.'/photos')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing([$newPhoto->id, $ownPhoto->id], array_column($photos, 'id'));
        $this->assertDatabaseHas('strain_photos', ['strain_id' => $this->strain->id, 'document_id' => $oldPhoto->id]);
    }

    public function test_gallery_is_private_deduplicated_and_excludes_archived_and_non_image_files(): void
    {
        $photo = $this->photo();
        $archived = $this->photo();
        $archived->update(['is_archived' => true]);
        $note = app(DocumentLibrary::class)->upload($this->admin, ['title' => 'Fictional note', 'subject' => ['type' => 'site', 'id' => $this->site->id]], UploadedFile::fake()->createWithContent('note.txt', 'Fictional note.'));
        $this->plant->forceFill(['photo_document_id' => $photo->id])->save();
        $this->plant->events()->first()->documents()->attach([$photo->id, $archived->id, $note->id]);
        $url = '/api/v1/plants/'.$this->plant->id.'/photos';
        $this->getJson($url)->assertUnauthorized();
        $this->getJson('/api/v1/documents/'.$photo->id.'/preview')->assertUnauthorized();
        $reader = User::factory()->create();
        $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $photo->id)->assertJsonPath('data.0.isCover', true)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/strains/'.$this->strain->id.'/photos')->assertOk()->assertJsonCount(0, 'data');
        $this->get('/api/v1/documents/'.$photo->id.'/preview')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Content-Disposition', 'inline')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/v1/documents/'.$note->id.'/preview')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->get('/api/v1/documents/'.$archived->id.'/preview')->assertOk();
        $this->getJson($url.'?page=0')->assertUnprocessable();
    }

    public function test_plant_gallery_respects_document_and_captured_event_locations_without_sharing_sibling_photos(): void
    {
        $outside = Site::create(['name' => 'Outside gallery site']);
        $hiddenPlant = $this->makePlant($outside);
        $visible = $this->photo();
        $hidden = $this->photo($outside);
        $this->plant->forceFill(['photo_document_id' => $visible->id])->save();
        $hiddenPlant->forceFill(['photo_document_id' => $hidden->id])->save();
        $hiddenPlant->events()->first()->documents()->attach($hidden->id);
        $captured = $this->plant->events()->first();
        $snapshot = $captured->snapshot;
        $snapshot['location'] = ['type' => 'site', 'id' => $outside->id, 'site' => ['id' => $outside->id, 'name' => $outside->name], 'room' => null, 'zone' => null];
        $captured->forceFill(['snapshot' => $snapshot])->save();
        $oldPhoto = $this->photo();
        $captured->documents()->attach($oldPhoto->id);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($reader)->getJson('/api/v1/strains/'.$this->strain->id.'/photos')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/plants/'.$this->plant->id.'/photos')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/plants/'.$hiddenPlant->id.'/photos')->assertNotFound();
        $this->get('/api/v1/documents/'.$hidden->id.'/preview')->assertNotFound();
    }

    public function test_gallery_pagination_is_stable_and_cover_is_first_even_when_older(): void
    {
        $photos = collect(range(1, 23))->map(fn () => $this->photo());
        $this->plant->events()->first()->documents()->attach($photos->pluck('id'));
        $cover = $photos->first();
        $cover->forceFill(['created_at' => now()->subYear()])->save();
        $this->plant->forceFill(['photo_document_id' => $cover->id])->save();
        $url = '/api/v1/plants/'.$this->plant->id.'/photos';
        $first = $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23)->assertJsonPath('data.0.id', $cover->id)->json('data');
        $second = $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertCount(23, array_unique(array_column([...$first, ...$second], 'id')));
        $this->getJson($url)->assertJsonPath('data', $first);
    }

    public function test_plants_inherit_live_strain_photos_until_they_choose_their_own_cover(): void
    {
        $sibling = $this->makePlant($this->site);
        $older = $this->photo();
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $latest = $this->photo();
        $this->strain->photos()->attach([$older->id, $latest->id]);
        $url = '/api/v1/plants/'.$this->plant->id;
        $inheritedUrl = '/api/v1/documents/'.$latest->id.'/preview?version='.$latest->version;
        $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonPath('data.photoUrl', $inheritedUrl)->assertJsonPath('data.hasOwnPhoto', false);
        $this->getJson('/api/v1/plants')->assertOk()->assertJsonPath('data.0.photoUrl', $inheritedUrl);
        $this->getJson('/api/v1/sites/'.$this->site->id.'/plant-dashboard')->assertOk()->assertJsonPath('data.0.photoUrl', $inheritedUrl);
        $this->getJson($url.'/photos')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $latest->id)->assertJsonPath('data.0.isCover', true);
        $this->get($inheritedUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertNull($this->plant->fresh()->photo_document_id);
        $this->assertDatabaseCount('documents', 2);

        $own = $this->photo();
        $this->patchJson($url, ['version' => 1, 'photo_document_id' => $own->id])->assertOk()->assertJsonPath('data.hasOwnPhoto', true)->assertJsonPath('data.photoUrl', $url.'/photo?version=2');
        $this->getJson($url.'/photos')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->patchJson($url, ['version' => 2, 'photo_document_id' => null])->assertOk()->assertJsonPath('data.hasOwnPhoto', false)->assertJsonPath('data.photoUrl', $inheritedUrl);
        $latest->update(['is_archived' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.photoUrl', '/api/v1/documents/'.$older->id.'/preview?version='.$older->version);
        $older->update(['is_archived' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.photoUrl', null);
        $this->getJson($url.'/photos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    }

    public function test_inherited_photos_respect_document_access_and_do_not_include_sibling_photos(): void
    {
        $outside = Site::create(['name' => 'Hidden inheritance site']);
        $hiddenPlant = $this->makePlant($outside);
        $hidden = $this->photo();
        $hiddenPlant->forceFill(['photo_document_id' => $hidden->id])->save();
        $sibling = $this->makePlant($this->site);
        $visible = $this->photo();
        $sibling->forceFill(['photo_document_id' => $visible->id])->save();
        $this->strain->photos()->attach($visible->id);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $url = '/api/v1/plants/'.$this->plant->id;
        $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonPath('data.photoUrl', '/api/v1/documents/'.$visible->id.'/preview?version='.$visible->version);
        $this->getJson($url.'/photos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
        $visible->forceFill(['site_id' => $outside->id, 'subject_snapshot' => ['type' => 'site', 'id' => $outside->id, 'location' => ['site' => ['id' => $outside->id, 'name' => $outside->name]]]])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.photoUrl', null);
        $this->getJson($url.'/photos')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_strain_photos_are_versioned_shared_without_copies_and_keep_plant_upload_history(): void
    {
        $url = '/api/v1/strains/'.$this->strain->id;
        $photo = $this->photo();
        $this->actingAs($this->admin)->patchJson($url, ['version' => 1, 'photo_document_id' => $photo->id])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($url, ['version' => 2, 'photo_document_id' => $photo->id])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('strain_photos', 1);
        $other = $this->photo();
        $this->patchJson($url, ['version' => 1, 'photo_document_id' => $other->id])->assertConflict();
        $other->update(['is_archived' => true]);
        $this->patchJson($url, ['version' => 2, 'photo_document_id' => $other->id])->assertUnprocessable();
        $plantUrl = '/api/v1/plants/'.$this->plant->id;
        $own = $this->photo();
        $next = $this->photo();
        $this->patchJson($plantUrl, ['version' => 1, 'photo_document_id' => $own->id])->assertOk();
        $this->patchJson($plantUrl, ['version' => 2, 'photo_document_id' => $next->id])->assertOk();
        $this->getJson($plantUrl.'/photos')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $next->id);
        $this->getJson($url.'/photos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $photo->id);
        $this->assertDatabaseCount('documents', 4);
        $reader = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id]]);
        $this->actingAs($reader)->patchJson($url, ['version' => 2, 'photo_document_id' => $next->id])->assertForbidden();
    }

    public function test_global_strain_uploads_are_readable_in_allowed_plants_but_only_strain_managers_can_create_them(): void
    {
        $reader = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id], 'location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+c+XcAAAAASUVORK5CYII=');
        $input = ['title' => 'Strain image', 'subject' => ['type' => 'strain', 'id' => $this->strain->id], 'file' => UploadedFile::fake()->createWithContent('strain.png', $image)];
        $this->actingAs($reader)->postJson('/api/v1/documents', $input)->assertForbidden();
        $id = $this->actingAs($this->admin)->postJson('/api/v1/documents', $input)->assertCreated()->assertJsonPath('data.disk', 'documents')->json('data.id');
        $this->patchJson('/api/v1/strains/'.$this->strain->id, ['version' => 1, 'photo_document_id' => $id])->assertOk();
        $this->actingAs($reader)->getJson('/api/v1/plants/'.$this->plant->id.'/photos')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->get('/api/v1/documents/'.$id.'/preview')->assertOk();
        $this->getJson('/api/v1/documents?format=image')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/plants/'.$this->plant->id)->assertOk()->assertJsonPath('data.photoUrl', '/api/v1/documents/'.$id.'/preview?version=1');
    }

    public function test_history_uses_occurrence_dates_across_pages_with_stable_equal_time_ties(): void
    {
        $seed = $this->plant->events()->first();
        $seed->forceFill(['occurred_at' => now()->subDays(7), 'recorded_at' => now()->subDays(7)])->save();
        foreach (range(1, 22) as $number) {
            $event = $seed->replicate(['id']);
            $event->forceFill(['id' => (string) Str::uuid(), 'plant_version' => $number + 1, 'comment' => 'Event '.$number,
                'occurred_at' => $number === 22 ? now()->subDays(9) : ($number >= 20 ? now()->subDay() : now()->subDays(3)->addMinutes($number)),
                'recorded_at' => now(), 'request_id' => null, 'request_hash' => null])->save();
        }
        $url = '/api/v1/plants/'.$this->plant->id.'/events';
        $first = $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.comment', 'Event 21')->assertJsonPath('data.1.comment', 'Event 20')->json('data');
        $second = $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.2.comment', 'Event 22')->json('data');
        $timestamps = array_map(fn ($event) => strtotime($event['occurredAt']), [...$first, ...$second]);
        $sorted = $timestamps;
        rsort($sorted);
        $this->assertSame($sorted, $timestamps);
        $this->assertCount(23, array_unique(array_column([...$first, ...$second], 'id')));
    }
}
