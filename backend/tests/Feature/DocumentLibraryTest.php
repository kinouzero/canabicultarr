<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Site;
use App\Models\User;
use App\Services\DocumentLibrary;
use App\Services\EventJournal;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DocumentLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
    }

    public function test_storage_is_chosen_per_upload_and_existing_files_keep_their_disk(): void
    {
        config(['documents.disk' => 'documents_s3', 'filesystems.disks.documents_s3.key' => 'fictional-key',
            'filesystems.disks.documents_s3.secret' => 'fictional-secret', 'filesystems.disks.documents_s3.bucket' => 'fictional-bucket']);
        Storage::fake('documents_s3');
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/documents/storage-options')->assertOk()
            ->assertExactJson(['data' => ['default' => 'documents_s3', 'disks' => ['documents', 'documents_s3']]]);
        foreach (['documents', 'documents_s3'] as $disk) {
            $id = $this->postJson('/api/v1/documents', ['title' => $disk, 'file' => $this->file(), 'disk' => $disk])
                ->assertCreated()->assertJsonPath('data.disk', $disk)->json('data.id');
            $document = Document::findOrFail($id);
            Storage::disk($disk)->assertExists($document->path);
            $this->get('/api/v1/documents/'.$id.'/download')->assertOk()->assertStreamedContent('Fictional operating instructions');
            $this->patchJson('/api/v1/documents/'.$id, ['version' => 1, 'disk' => 'documents'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/documents', ['title' => 'Invalid', 'file' => $this->file(), 'disk' => 'public'])
            ->assertUnprocessable()->assertJsonValidationErrors('disk');
        config(['filesystems.disks.documents_s3.key' => '']);
        $this->getJson('/api/v1/documents/storage-options')->assertJsonPath('data.disks', ['documents']);
        $this->postJson('/api/v1/documents', ['title' => 'Unavailable', 'file' => $this->file(), 'disk' => 'documents_s3'])
            ->assertUnprocessable()->assertJsonValidationErrors('disk');
    }

    private function file(string $name = 'manual.txt', string $content = 'Fictional operating instructions'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload(User $user, array $data = []): Document
    {
        return app(DocumentLibrary::class)->upload($user, ['title' => 'Test Manual', ...$data], $this->file());
    }

    public function test_guests_cannot_access_metadata_download_or_upload(): void
    {
        $document = $this->upload(User::factory()->create());
        foreach (['/api/v1/documents', '/api/v1/documents/'.$document->id, '/api/v1/documents/'.$document->id.'/download'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/v1/documents', ['title' => 'Denied', 'file' => $this->file()])->assertUnauthorized();
        $this->patchJson('/api/v1/documents/'.$document->id, ['version' => 1, 'title' => 'Denied'])->assertUnauthorized();
    }

    public function test_upload_uses_server_metadata_private_storage_and_checksum(): void
    {
        $user = User::factory()->create();
        $forged = (string) Str::uuid();
        $result = $this->actingAs($user)->postJson('/api/v1/documents', ['title' => ' Manual ', 'file' => $this->file(), 'sha256' => 'forged', 'path' => 'public/forged', 'id' => $forged, 'uploaded_by' => 999, 'is_archived' => true, 'version' => 99])
            ->assertCreated()->assertJsonPath('data.title', 'Manual')->assertJsonPath('data.version', 1)->assertJsonPath('data.isArchived', false)->assertJsonPath('data.uploader.id', $user->uuid)->assertJsonPath('data.canEdit', true)
            ->assertJsonPath('data.mediaType', 'text/plain')->assertJsonPath('data.format', 'text')->assertJsonPath('data.sha256', hash('sha256', 'Fictional operating instructions'))
            ->assertJsonMissingPath('data.path')->assertJsonPath('data.disk', 'documents')->assertJsonMissingPath('data.uploaded_by')->assertJsonMissingPath('data.uploader.email')->json('data');
        $this->assertNotSame($forged, $result['id']);
        $this->assertTrue(Str::isUuid($result['id']));
        $document = Document::findOrFail($result['id']);
        Storage::disk('documents')->assertExists($document->path);
        $this->assertSame('private', Storage::disk('documents')->getVisibility($document->path));
        $this->assertSame($document->id.'/original.txt', $document->path);
    }

    public function test_private_preview_supports_pdf_text_csv_and_archived_files_without_download_disposition(): void
    {
        $user = User::factory()->create();
        foreach ([['note.txt', 'Fictional preview text', 'text/plain; charset=utf-8'], ['values.csv', "name,value\nfictional,35\n", null], ['report.pdf', "%PDF-1.4\nFictional preview PDF", 'application/pdf']] as [$name, $content, $mime]) {
            $document = app(DocumentLibrary::class)->upload($user, ['title' => 'Preview'], $this->file($name, $content));
            $document->update(['is_archived' => true]);
            $response = $this->actingAs($user)->get('/api/v1/documents/'.$document->id.'/preview')
                ->assertOk()->assertHeader('Content-Disposition', 'inline')
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
            if ($mime) {
                $response->assertHeader('Content-Type', $mime);
            }
            $this->assertSame($content, $response->streamedContent());
        }
    }

    public function test_text_previews_are_bounded_on_each_disk_while_downloads_remain_complete(): void
    {
        Storage::fake('documents_s3');
        config(['filesystems.disks.documents_s3.key' => 'fictional-key',
            'filesystems.disks.documents_s3.secret' => 'fictional-secret', 'filesystems.disks.documents_s3.bucket' => 'fictional-bucket']);
        $user = User::factory()->create();
        $limit = 1024 * 1024;
        foreach (['documents', 'documents_s3'] as $disk) {
            foreach ([$limit * 8, $limit, $limit - 1] as $size) {
                $line = "Fictional preview line.\n";
                $content = substr(str_repeat($line, (int) ceil($size / strlen($line))), 0, $size);
                $document = app(DocumentLibrary::class)->upload($user, ['title' => 'Preview bound', 'disk' => $disk], $this->file('large.txt', $content));
                $preview = $this->actingAs($user)->get('/api/v1/documents/'.$document->id.'/preview')->assertOk();
                $this->assertSame(min($limit, $size), strlen($preview->streamedContent()));
                $preview->assertHeader('Content-Length', (string) min($limit, $size))
                    ->assertHeader('X-Preview-Truncated', $size > $limit ? 'true' : 'false');
                $this->assertSame(substr($content, 0, $limit), $preview->streamedContent());
                $download = $this->get('/api/v1/documents/'.$document->id.'/download')->assertOk()
                    ->assertHeader('Content-Length', (string) $size);
                $this->assertSame($content, $download->streamedContent());
            }
        }
    }

    public function test_preview_requires_current_access_and_rejects_unsafe_mime_and_missing_bytes(): void
    {
        $user = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Preview site']);
        $document = $this->upload($user, ['subject' => ['type' => 'site', 'id' => $site->id]]);
        $url = '/api/v1/documents/'.$document->id.'/preview';
        $this->getJson($url)->assertUnauthorized();
        $otherSite = Site::create(['name' => 'Other preview site']);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $otherSite->id]]]);
        $this->actingAs($reader)->getJson($url)->assertNotFound();
        $this->actingAs($user);
        $document->forceFill(['media_type' => 'text/html'])->save();
        $this->getJson($url)->assertNotFound();
        $document->forceFill(['media_type' => 'text/plain'])->save();
        Storage::disk('documents')->delete($document->path);
        $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'documents.errors.fileUnavailable');
        $this->actingAs(User::factory()->create(['is_active' => false]))->getJson($url)->assertUnauthorized();
    }

    public function test_download_streams_exact_bytes_with_attachment_headers(): void
    {
        $owner = User::factory()->create();
        $document = app(DocumentLibrary::class)->upload($owner, ['title' => 'Unicode'], $this->file('procédure.txt', 'Exact fictional bytes'));
        $response = $this->actingAs(User::factory()->create())->get('/api/v1/documents/'.$document->id.'/download');
        $response->assertOk()->assertHeader('content-type', 'text/plain; charset=utf-8')->assertHeader('content-length', '21')->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
        $this->assertStringContainsString('sandbox', $response->headers->get('content-security-policy'));
        $this->assertSame('Exact fictional bytes', $response->streamedContent());
    }

    public function test_members_only_edit_own_metadata_and_admins_edit_others(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $document = $this->upload($owner);
        $this->actingAs($other)->getJson('/api/v1/documents/'.$document->id)->assertOk()->assertJsonPath('data.canEdit', false);
        $this->patchJson('/api/v1/documents/'.$document->id, ['version' => 1, 'title' => 'Denied'])->assertForbidden();
        $this->actingAs($owner)->patchJson('/api/v1/documents/'.$document->id, ['version' => 1, 'title' => 'Owner change'])->assertOk()->assertJsonPath('data.version', 2);
        $this->actingAs($admin)->patchJson('/api/v1/documents/'.$document->id, ['version' => 2, 'description' => 'Admin change'])->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.uploader.id', $owner->uuid);
    }

    public function test_archive_is_reversible_downloadable_and_filtered(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        $url = '/api/v1/documents/'.$document->id;
        $this->actingAs($user)->patchJson($url, ['version' => 1, 'is_archived' => true])->assertOk()->assertJsonPath('data.isArchived', true);
        $this->getJson('/api/v1/documents')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/documents?state=archived')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/documents?state=all')->assertJsonCount(1, 'data');
        $this->get($url.'/download')->assertOk();
        $this->patchJson($url, ['version' => 2, 'is_archived' => false])->assertOk()->assertJsonPath('data.version', 3);
        $this->getJson('/api/v1/documents')->assertJsonCount(1, 'data');
    }

    public function test_versions_prevent_lost_updates_and_noop_preserves_version(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        $url = '/api/v1/documents/'.$document->id;
        $this->actingAs($user)->patchJson($url, ['version' => 1, 'title' => 'Test Manual'])->assertOk()->assertJsonPath('data.version', 1);
        $this->patchJson($url, ['version' => 1, 'title' => 'Updated'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($url, ['version' => 1, 'title' => 'Lost'])->assertConflict()->assertJsonPath('error.code', 'documents.errors.conflict');
        $this->patchJson($url, ['title' => 'Missing version'])->assertUnprocessable();
        $this->assertSame('Updated', $document->fresh()->title);
    }

    public function test_binary_is_immutable_and_missing_blob_is_a_safe_error(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        $url = '/api/v1/documents/'.$document->id;
        $this->actingAs($user)->patchJson($url, ['version' => 1, 'file' => $this->file('replacement.txt', 'Replacement')])->assertUnprocessable();
        Storage::disk('documents')->delete($document->path);
        $this->getJson($url.'/download')->assertNotFound()->assertExactJson(['error' => ['code' => 'documents.errors.fileUnavailable']]);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_content_extension_empty_and_size_validation_leave_no_records_or_blobs(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach ([['manual.html', '<html><body>Script</body></html>'], ['manual.pdf', 'Plain text pretending to be PDF'], ['manual.svg', '<svg></svg>'], ['empty.txt', ''], ['large.txt', str_repeat('x', 10485761)]] as [$name,$content]) {
            $this->postJson('/api/v1/documents', ['title' => 'Rejected', 'file' => $this->file($name, $content)])->assertUnprocessable()->assertJsonValidationErrors('file');
            try {
                app(DocumentLibrary::class)->upload($user, ['title' => 'Rejected service upload'], $this->file($name, $content));
                $this->fail('Direct service upload accepted invalid content.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_allowed_formats_are_recognized_from_content(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach ([['manual.pdf', "%PDF-1.4\n1 0 obj<</Type /Catalog>>endobj\n%%EOF", 'application/pdf'], ['table.csv', "name,value\nprobe,12\n", 'text/plain'], ['image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII='), 'image/png']] as [$name,$bytes,$mime]) {
            $this->postJson('/api/v1/documents', ['title' => 'Allowed', 'file' => $this->file($name, $bytes)])->assertCreated()->assertJsonPath('data.mediaType', $mime);
        }
    }

    public function test_context_captures_location_and_event_without_mutating_journal(): void
    {
        $user = User::factory()->create();
        $site = Site::create(['name' => 'Original Site']);
        $event = app(EventJournal::class)->save($user, ['title' => 'Original Event', 'body' => 'Notes', 'category' => 'note', 'severity' => 'info', 'occurred_at' => '2026-11-06T12:00:00Z', 'subject' => ['type' => 'site', 'id' => $site->id]]);
        $document = $this->upload($user, ['subject' => ['type' => 'event', 'id' => $event->id]]);
        $this->assertSame($event->id, $document->event_entry_id);
        $this->assertSame($site->id, $document->site_id);
        $this->assertSame('Original Event', $document->subject_snapshot['name']);
        app(EventJournal::class)->save($user, ['version' => 1, 'title' => 'Renamed Event'], $event);
        $this->actingAs($user)->patchJson('/api/v1/documents/'.$document->id, ['version' => 1, 'subject' => ['type' => 'event', 'id' => $event->id]])->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.subject.name', 'Original Event');
        $this->getJson('/api/v1/documents?event_entry_id='.$event->id)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/documents?site_id='.$site->id)->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/documents/'.$document->id, ['version' => 1, 'subject' => null])->assertOk()->assertJsonPath('data.subject', null);
        $this->assertNull($document->fresh()->event_entry_id);
        $this->assertDatabaseCount('event_revisions', 2);
    }

    public function test_invalid_target_is_rejected_before_storage_write(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/documents', ['title' => 'Missing', 'file' => $this->file(), 'subject' => ['type' => 'site', 'id' => (string) Str::uuid()]])->assertNotFound();
        $this->postJson('/api/v1/documents', ['title' => 'Malformed', 'file' => $this->file(), 'subject' => ['type' => 'document', 'id' => (string) Str::uuid()]])->assertUnprocessable();
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_search_is_literal_case_insensitive_and_paginated(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 22; $i++) {
            $this->upload($user, ['title' => $i === 0 ? '100%_Manual' : 'Manual '.$i, 'description' => 'Searchable description']);
        }
        $this->actingAs($user)->getJson('/api/v1/documents')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 22);
        $this->getJson('/api/v1/documents?page=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/documents?search='.urlencode('%_manual'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/documents?search=SEARCHABLE&format=text')->assertJsonPath('meta.total', 22);
        $this->getJson('/api/v1/documents?format=pdf')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/documents?page=0')->assertUnprocessable();
    }

    public function test_storage_failure_is_sanitized_and_does_not_create_metadata(): void
    {
        $user = User::factory()->create();
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('secret provider details'));
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('documents')->andReturn($disk);
        $this->actingAs($user)->postJson('/api/v1/documents', ['title' => 'Failure', 'file' => $this->file()])->assertStatus(503)->assertExactJson(['error' => ['code' => 'documents.errors.storageUnavailable']]);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_database_failure_cleans_only_the_new_blob(): void
    {
        $user = User::factory()->create();
        $existing = $this->upload($user);
        $path = $existing->path;
        Document::creating(function () {
            throw new \RuntimeException('forced save failure');
        });
        try {
            $this->upload($user);
            $this->fail('Expected database save failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced save failure', $exception->getMessage());
        } finally {
            Document::flushEventListeners();
        }
        $this->assertDatabaseCount('documents', 1);
        $this->assertSame([$path], Storage::disk('documents')->allFiles());
    }

    public function test_fresh_actor_state_is_checked_inside_service(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        User::whereKey($user->id)->update(['is_active' => false]);
        $this->actingAs($user->fresh())->getJson('/api/v1/documents')->assertUnauthorized();
        try {
            $this->upload($user);
            $this->fail('Inactive actor accepted');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('documents', 1);
        }
    }

    public function test_upload_rate_limit_does_not_limit_reads(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/documents', ['title' => 'Rate test', 'file' => $this->file()])->assertCreated();
        }
        $this->postJson('/api/v1/documents', ['title' => 'Limited', 'file' => $this->file()])->assertTooManyRequests();
        $this->getJson('/api/v1/documents')->assertOk();
    }

    public function test_private_disk_has_no_public_serving_route(): void
    {
        $this->assertFalse(config('filesystems.disks.documents.serve'));
        $this->assertSame('private', config('filesystems.disks.documents.visibility'));
        $this->assertSame('documents', config('documents.disk'));
    }

    public function test_maximum_size_is_inclusive_and_request_limit_is_translated(): void
    {
        $user = User::factory()->create();
        $file = $this->file('boundary.txt', str_repeat('x', 10485760));
        $this->actingAs($user)->postJson('/api/v1/documents', ['title' => 'Boundary', 'file' => $file])
            ->assertCreated()->assertJsonPath('data.sizeBytes', 10485760);
        $this->call('POST', '/api/v1/documents', [], [], [], ['CONTENT_LENGTH' => '12582913', 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(413)->assertJsonPath('error.code', 'documents.errors.tooLarge');
    }

    public function test_download_storage_errors_do_not_expose_provider_details(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->andThrow(new \RuntimeException('private endpoint and credentials'));
        Storage::shouldReceive('disk')->with('documents')->andReturn($disk);
        $this->actingAs($user)->getJson('/api/v1/documents/'.$document->id.'/download')->assertStatus(503)
            ->assertExactJson(['error' => ['code' => 'documents.errors.storageUnavailable']]);
    }

    public function test_public_disks_are_never_allowed_for_uploads(): void
    {
        $user = User::factory()->create();
        config(['documents.disk' => 'public']);
        $this->actingAs($user)->postJson('/api/v1/documents', ['title' => 'Denied', 'file' => $this->file()])
            ->assertStatus(503)->assertExactJson(['error' => ['code' => 'documents.errors.storageUnavailable']]);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_additive_migration_preserves_records_and_foreign_keys_protect_current_context(): void
    {
        $user = User::factory()->create();
        $site = Site::create(['name' => 'Preserved Site']);
        $event = app(EventJournal::class)->save($user, ['title' => 'Preserved Event', 'body' => 'Notes', 'category' => 'note', 'severity' => 'info', 'occurred_at' => '2026-11-06T12:00:00Z']);
        $this->reapplySchemaFrom('2026_11_06_090000_create_documents.php');
        $this->assertDatabaseHas('users', ['uuid' => $user->uuid]);
        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('event_entries', ['id' => $event->id]);
        $this->assertDatabaseCount('event_revisions', 1);
        $document = $this->upload($user, ['subject' => ['type' => 'site', 'id' => $site->id]]);
        try {
            DB::transaction(fn () => $site->delete());
            $this->fail('Referenced location deletion must fail');
        } catch (QueryException) {
            $this->assertDatabaseHas('sites', ['id' => $site->id]);
        }
        $user->delete();
        $this->assertNull($document->fresh()->uploaded_by);
        $this->assertSame($user->uuid, $document->fresh()->uploader_uuid);
    }

    public function test_audit_messages_only_record_identifiers_and_changed_field_names(): void
    {
        $user = User::factory()->create();
        Log::spy();
        $document = $this->upload($user, ['description' => 'Sensitive document description']);
        app(DocumentLibrary::class)->update($user, $document, ['version' => 1, 'title' => 'Private new title']);
        Log::shouldHaveReceived('notice')->with('document.uploaded', ['actor_uuid' => $user->uuid, 'document_uuid' => $document->id])->once();
        Log::shouldHaveReceived('notice')->with('document.updated', ['actor_uuid' => $user->uuid, 'document_uuid' => $document->id, 'fields' => ['title'], 'version' => 2])->once();
    }
}
