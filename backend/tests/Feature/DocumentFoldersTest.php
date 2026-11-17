<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Site;
use App\Models\User;
use App\Services\DocumentFolders;
use App\Services\DocumentLibrary;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentFoldersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
    }

    private function folder(User $user, string $name, ?string $parent = null): DocumentFolder
    {
        return app(DocumentFolders::class)->save($user, null, ['name' => $name, 'parent_id' => $parent]);
    }

    private function file(User $user, array $data = []): Document
    {
        return app(DocumentLibrary::class)->upload($user, ['title' => 'Fictional instructions', ...$data], UploadedFile::fake()->createWithContent('note.txt', 'Private sample'));
    }

    public function test_large_trees_load_only_the_requested_page_and_ancestor_chain(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $rows = [];
        $roots = [];
        for ($i = 0; $i < 250; $i++) {
            $id = (string) Str::uuid();
            $roots[] = $id;
            $rows[] = ['id' => $id, 'name' => sprintf('Root %03d', $i), 'name_key' => sprintf('root %03d', $i), 'parent_key' => 'root', 'parent_id' => null, 'created_by' => $user->id];
            for ($j = 0; $j < 20; $j++) {
                $rows[] = ['id' => (string) Str::uuid(), 'name' => 'Shared '.$j, 'name_key' => 'shared '.$j, 'parent_key' => $id, 'parent_id' => $id, 'created_by' => $user->id];
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('document_folders')->insert($chunk);
        }
        $beforeBytes = strlen(json_encode(DocumentFolder::all()->map(fn ($folder) => ['id' => $folder->id, 'name' => $folder->name, 'parentId' => $folder->parent_id, 'version' => $folder->version, 'canEdit' => true])));
        $loaded = 0;
        DocumentFolder::retrieved(function () use (&$loaded) {
            $loaded++;
        });
        $rootPage = $this->getJson('/api/v1/document-folders')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 250)->assertJsonPath('data.0.hasChildren', true);
        $this->assertSame(50, $loaded);
        $this->getJson('/api/v1/document-folders?parent_id='.$roots[0])->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 20)->assertJsonPath('data.0.hasChildren', false);
        $child = $rows[1]['id'];
        $path = $this->getJson('/api/v1/document-folders/'.$child)->assertOk()->assertJsonCount(2, 'ancestors')->assertJsonPath('ancestors.0.id', $roots[0])->assertJsonPath('ancestors.1.id', $child);
        $this->assertSame($child, $path->json('data.id'));
        $this->getJson('/api/v1/document-folders/paths?'.http_build_query(['ids' => [$child]]))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/document-folders/paths?'.http_build_query(['ids' => array_fill(0, 101, $child)]))->assertUnprocessable();
        $this->getJson('/api/v1/document-folders?parent_id='.fake()->uuid())->assertUnprocessable();
        $this->getJson('/api/v1/document-folders?per_page=101')->assertUnprocessable();
        $afterBytes = strlen($rootPage->getContent());
        $this->assertLessThan($beforeBytes / 20, $afterBytes);
        $beforeWrite = $loaded;
        $this->folder($user, 'Scoped child', $roots[0]);
        $this->assertSame(1, $loaded - $beforeWrite);
        file_put_contents('/tmp/cana53-folder-benchmark.json', json_encode(['beforeRows' => count($rows), 'afterRows' => 50, 'beforeBytes' => $beforeBytes, 'afterBytes' => $afterBytes]));
    }

    public function test_guests_are_denied_and_folder_metadata_does_not_expose_owners(): void
    {
        $this->getJson('/api/v1/document-folders')->assertUnauthorized();
        $this->postJson('/api/v1/document-folders', ['name' => 'Denied', 'parent_id' => null])->assertUnauthorized();
        $user = User::factory()->create();
        $id = $this->actingAs($user)->postJson('/api/v1/document-folders', ['name' => ' Manuals ', 'parent_id' => null, 'created_by' => 999, 'version' => 90])
            ->assertCreated()->assertJsonPath('data.name', 'Manuals')->assertJsonPath('data.parentId', null)->assertJsonPath('data.version', 1)->assertJsonPath('data.canEdit', true)->assertJsonMissingPath('data.created_by')->json('data.id');
        $this->getJson('/api/v1/document-folders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    }

    public function test_same_name_is_allowed_at_each_depth_and_in_other_branches_but_not_among_siblings(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $root = $this->postJson('/api/v1/document-folders', ['name' => 'Photos', 'parent_id' => null])->assertCreated()->json('data.id');
        $child = $this->postJson('/api/v1/document-folders', ['name' => 'Photos', 'parent_id' => $root])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/document-folders', ['name' => 'Photos', 'parent_id' => $child])->assertCreated();
        $branch = $this->folder($user, 'Another branch');
        $this->postJson('/api/v1/document-folders', ['name' => 'Photos', 'parent_id' => $branch->id])->assertCreated();
        $this->postJson('/api/v1/document-folders', ['name' => ' PHOTOS ', 'parent_id' => $root])->assertUnprocessable()->assertJsonPath('errors.name.0', 'documents.errors.folderNameTaken');
        $this->patchJson('/api/v1/document-folders/'.$child, ['name' => 'Photos', 'parent_id' => $branch->id, 'version' => 1])->assertUnprocessable();
        $this->patchJson('/api/v1/document-folders/'.$child, ['name' => 'Photos', 'parent_id' => $root, 'version' => 1])->assertOk()->assertJsonPath('data.parentId', $root);
        $this->assertSame($root, DocumentFolder::findOrFail($child)->parent_id);
    }

    public function test_tree_edits_reject_cycles_missing_parents_duplicates_and_stale_versions(): void
    {
        $user = User::factory()->create();
        $root = $this->folder($user, 'Manuals');
        $child = $this->folder($user, 'Equipment', $root->id);
        $this->actingAs($user)->patchJson('/api/v1/document-folders/'.$root->id, ['name' => 'Manuals', 'parent_id' => $child->id, 'version' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/document-folders', ['name' => 'MANUALS', 'parent_id' => null])->assertUnprocessable();
        $this->postJson('/api/v1/document-folders', ['name' => 'Manuals', 'parent_id' => $child->id])->assertCreated();
        $this->postJson('/api/v1/document-folders', ['name' => 'Unknown parent', 'parent_id' => fake()->uuid()])->assertUnprocessable();
        foreach (['..', '.', 'a/b', 'a\\b', '   '] as $name) {
            $this->postJson('/api/v1/document-folders', ['name' => $name, 'parent_id' => null])->assertUnprocessable();
        }
        $this->patchJson('/api/v1/document-folders/'.$child->id, ['name' => 'Hardware', 'parent_id' => null, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.hasChildren', true);
        $this->patchJson('/api/v1/document-folders/'.$child->id, ['name' => 'Stale', 'parent_id' => null, 'version' => 1])->assertConflict();
        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_folder_and_file_editing_retains_owner_and_administrator_boundaries(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $folder = $this->folder($owner, 'Shared folder');
        $file = $this->file($owner);
        $this->actingAs($other)->getJson('/api/v1/document-folders')->assertOk()->assertJsonPath('data.0.canEdit', false);
        $this->patchJson('/api/v1/document-folders/'.$folder->id, ['name' => 'Denied', 'parent_id' => null, 'version' => 1])->assertForbidden();
        $this->deleteJson('/api/v1/document-folders/'.$folder->id, ['version' => 1])->assertForbidden();
        $this->patchJson('/api/v1/documents/'.$file->id, ['folder_id' => $folder->id, 'version' => 1])->assertForbidden();
        $this->actingAs($admin)->patchJson('/api/v1/documents/'.$file->id, ['folder_id' => $folder->id, 'version' => 1])->assertOk()->assertJsonPath('data.folderId', $folder->id)->assertJsonPath('data.version', 2);
        $this->actingAs($owner)->patchJson('/api/v1/documents/'.$file->id, ['folder_id' => null, 'version' => 2])->assertOk()->assertJsonPath('data.folderId', null)->assertJsonPath('data.version', 3);
        $this->assertSame('Private sample', Storage::disk('documents')->get($file->path));
    }

    public function test_root_and_all_file_queries_and_upload_destination_are_distinct(): void
    {
        $user = User::factory()->create();
        $folder = $this->folder($user, 'Photos');
        $root = $this->file($user, ['title' => 'Root']);
        $this->actingAs($user)->postJson('/api/v1/documents', ['title' => 'Nested', 'folder_id' => $folder->id, 'file' => UploadedFile::fake()->createWithContent('nested.txt', 'Sample')])->assertCreated()->assertJsonPath('data.folderId', $folder->id);
        $this->getJson('/api/v1/documents?folder_id=')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $root->id);
        $this->getJson('/api/v1/documents?folder_id='.$folder->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Nested');
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/documents', ['title' => 'Invalid', 'folder_id' => fake()->uuid(), 'file' => UploadedFile::fake()->createWithContent('invalid.txt', 'Sample')])->assertUnprocessable();
        $this->assertSame(2, Document::count());
    }

    public function test_only_empty_folders_can_be_deleted_including_archived_hidden_content(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $user = User::factory()->create(['location_access' => []]);
        $folder = $this->folder($user, 'Documents');
        $child = $this->folder($user, 'Child', $folder->id);
        $this->actingAs($user)->deleteJson('/api/v1/document-folders/'.$folder->id, ['version' => 1])->assertConflict()->assertJsonPath('error.code', 'documents.errors.folderNotEmpty');
        $this->deleteJson('/api/v1/document-folders/'.$child->id, ['version' => 1])->assertNoContent();
        $site = Site::create(['name' => 'Private site']);
        $this->actingAs($admin);
        $file = $this->file($admin, ['folder_id' => $folder->id, 'subject' => ['type' => 'site', 'id' => $site->id]]);
        $file->update(['is_archived' => true]);
        $this->actingAs($user)->getJson('/api/v1/documents?folder_id='.$folder->id)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/document-folders/'.$folder->id, ['version' => 1])->assertConflict();
        $this->assertNotNull($file->fresh());
    }

    public function test_reorganizing_a_file_preserves_context_visibility_and_archive_and_preview_rules(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $user = User::factory()->create(['location_access' => []]);
        $folder = $this->folder($admin, 'Shared');
        $site = Site::create(['name' => 'Hidden']);
        $file = $this->file($admin, ['subject' => ['type' => 'site', 'id' => $site->id]]);
        $this->actingAs($admin)->patchJson('/api/v1/documents/'.$file->id, ['version' => 1, 'folder_id' => $folder->id, 'is_archived' => true])->assertOk();
        $this->actingAs($user)->getJson('/api/v1/documents?state=all&folder_id='.$folder->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/documents/'.$file->id.'/preview')->assertNotFound();
        $this->actingAs($admin)->get('/api/v1/documents/'.$file->id.'/preview')->assertOk();
        $this->assertSame($site->id, $file->fresh()->site_id);
    }

    public function test_file_sorting_combines_with_folder_search_and_archive_filters(): void
    {
        $user = User::factory()->create();
        $folder = $this->folder($user, 'Sorted');
        $alpha = $this->file($user, ['title' => 'Alpha', 'folder_id' => $folder->id]);
        $zulu = $this->file($user, ['title' => 'Zulu', 'folder_id' => $folder->id]);
        $alpha->forceFill(['created_at' => now()->subDays(2), 'size_bytes' => 500])->save();
        $this->file($user, ['title' => 'Elsewhere']);
        $path = '/api/v1/documents?folder_id='.$folder->id;
        $this->actingAs($user)->getJson($path.'&sort=name')->assertJsonPath('data.0.id', $alpha->id)->assertJsonCount(2, 'data');
        $this->getJson($path.'&sort=newest')->assertJsonPath('data.0.id', $zulu->id);
        $this->getJson($path.'&sort=oldest')->assertJsonPath('data.0.id', $alpha->id);
        $this->getJson($path.'&sort=size')->assertJsonPath('data.0.id', $alpha->id);
        $this->getJson($path.'&search=alpha')->assertJsonCount(1, 'data');
        $alpha->update(['is_archived' => true]);
        $this->getJson($path.'&state=archived')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alpha->id);
        $this->getJson($path.'&sort=invalid')->assertUnprocessable();
    }
}
