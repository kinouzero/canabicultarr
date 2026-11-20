<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\DocumentLibrary;
use App\Services\EventJournal;
use App\Services\LocationAccess;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobilePairingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://localhost/api/v1/account/mobile-devices';

    public function test_pairing_requires_authentication_and_secure_transport(): void
    {
        $this->postJson(self::URL.'/pairing')->assertUnauthorized();
        $this->postJson(self::URL.'/redeem', ['code' => str_repeat('x', 43), 'label' => 'Phone'])->assertUnauthorized();

        $this->actingAs(User::factory()->create());
        $this->postJson('http://localhost/api/v1/account/mobile-devices/pairing')->assertForbidden();
        $this->postJson('http://localhost/api/v1/account/mobile-devices/redeem', ['code' => str_repeat('x', 43), 'label' => 'Phone'])->assertForbidden();
    }

    public function test_forwarded_https_is_only_accepted_from_a_configured_proxy(): void
    {
        $this->actingAs(User::factory()->create());
        $url = 'http://localhost/api/v1/account/mobile-devices/pairing';
        $headers = ['X-Forwarded-Proto' => 'https'];
        config(['trustedproxy.proxies' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])->postJson($url, [], $headers)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->postJson($url, [], $headers)->assertCreated();
    }

    public function test_only_the_issuing_account_can_use_the_code_once_and_remove_its_registration(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issued = $this->actingAs($owner)->postJson(self::URL.'/pairing')->assertCreated();
        $this->assertStringContainsString('no-store', $issued->headers->get('Cache-Control'));
        $code = $issued->json('data.code');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $code);
        $this->assertDatabaseHas('mobile_devices', ['code_digest' => hash('sha256', $code)]);
        $this->assertDatabaseMissing('mobile_devices', ['code_digest' => $code]);
        $this->getJson(self::URL)->assertExactJson(['data' => []]);

        $payload = ['code' => $code, 'label' => 'Test phone'];
        $this->actingAs($other)->postJson(self::URL.'/redeem', $payload)
            ->assertUnprocessable()->assertJsonPath('error.code', 'mobilePairing.invalidCode');
        $device = $this->actingAs($owner)->postJson(self::URL.'/redeem', $payload)->assertCreated()->json('data.id');
        $this->assertSame($device, session('mobile_device_id'));
        $this->postJson(self::URL.'/redeem', $payload)->assertUnprocessable();
        $this->assertDatabaseHas('mobile_devices', ['id' => $device, 'code_digest' => null]);
        $this->getJson(self::URL)->assertJsonPath('data.0.label', 'Test phone')->assertJsonPath('data.0.current', true)->assertJsonMissingPath('data.0.code_digest');
        $this->actingAs($other)->deleteJson(self::URL.'/'.$device)->assertNotFound();
        $this->actingAs($owner)->deleteJson(self::URL.'/'.$device)->assertNoContent();
        $this->getJson(self::URL)->assertExactJson(['data' => []]);
    }

    public function test_offline_directory_requires_the_paired_session_and_tracks_location_access(): void
    {
        $site = Site::create(['name' => 'Allowed site']);
        $private = Site::create(['name' => 'Other site']);
        $member = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $this->actingAs($member);
        $endpoint = self::URL.'/offline-sites';
        $this->getJson($endpoint)->assertForbidden();
        $code = $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
        $device = $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone'])->assertCreated()->json('data.id');

        $snapshot = $this->getJson($endpoint)->assertOk()
            ->assertJsonCount(1, 'data.sites')
            ->assertJsonPath('data.sites.0.name', $site->name)
            ->assertJsonPath('data.accountId', $member->uuid)
            ->assertJsonPath('data.deviceId', $device)
            ->assertJsonPath('data.truncated', false);
        $this->assertStringContainsString('no-store', $snapshot->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($private->name, $snapshot->getContent());

        $member->forceFill(['location_access' => []])->save();
        $this->actingAs($member->refresh());
        $changed = $this->getJson($endpoint)->assertOk()->assertJsonCount(0, 'data.sites');
        $this->assertNotSame($snapshot->json('data.version'), $changed->json('data.version'));

        DB::table('mobile_devices')->where('id', $device)->delete();
        $this->getJson($endpoint)->assertForbidden();
    }

    public function test_mobile_journal_note_retries_are_idempotent_and_recheck_access(): void
    {
        $site = Site::create(['name' => 'Offline note site']);
        $other = Site::create(['name' => 'Unrelated site']);
        $member = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $this->actingAs($member);
        $url = self::URL.'/journal-notes';
        $input = [
            'operation_id' => (string) Str::uuid(),
            'site_id' => $site->id,
            'account_id' => $member->uuid,
            'device_id' => (string) Str::uuid(),
            'title' => 'Offline field note',
            'body' => 'Observed in the morning.',
            'occurred_at' => '2026-11-19T10:00:00+01:00',
        ];
        $this->postJson($url, $input)->assertForbidden();
        $code = $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
        $device = $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone'])->assertCreated()->json('data.id');
        $input['device_id'] = $device;
        $this->postJson($url, [...$input, 'account_id' => (string) Str::uuid()])->assertForbidden();
        $this->postJson($url, [...$input, 'device_id' => (string) Str::uuid()])->assertForbidden();
        $this->postJson($url, [...$input, 'site_id' => $other->id])->assertUnprocessable()
            ->assertJsonPath('error.code', 'offlineSites.siteUnavailable');
        $event = $this->postJson($url, $input)->assertCreated()->json('data.id');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $event);
        $this->postJson($url, [...$input, 'body' => 'Different note'])->assertConflict()
            ->assertJsonPath('error.code', 'offlineSites.operationConflict');
        $this->assertDatabaseCount('event_entries', 1);
        $this->assertDatabaseCount('event_revisions', 1);
        $this->assertDatabaseCount('mobile_journal_receipts', 1);
        $this->assertDatabaseHas('event_entries', ['id' => $event, 'site_id' => $site->id, 'created_by' => $member->id, 'category' => 'note', 'severity' => 'info']);

        $member->forceFill(['location_access' => []])->save();
        $this->actingAs($member->refresh());
        $this->postJson($url, $input)->assertUnprocessable();
        $this->deleteJson(self::URL.'/'.$device)->assertNoContent();
        $this->postJson($url, $input)->assertForbidden();
        $this->assertDatabaseCount('event_entries', 1);
    }

    public function test_paired_journal_pulls_scoped_changes_and_rejects_stale_scope(): void
    {
        $site = Site::create(['name' => 'Allowed site']);
        $private = Site::create(['name' => 'Private site']);
        $room = $site->rooms()->create(['name' => 'Room']);
        $author = User::factory()->create();
        $member = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $journal = app(EventJournal::class);
        $note = fn (Site $location, string $title) => app(LocationAccess::class)->run($author, fn () => $journal->save($author, [
            'title' => $title, 'body' => $title, 'category' => 'note', 'severity' => 'info',
            'occurred_at' => now()->toISOString(), 'subject' => ['type' => 'site', 'id' => $location->id],
        ]));
        $existing = $note($site, 'Before download');
        $note($private, 'Private note');

        $this->actingAs($member);
        $url = self::URL.'/offline-journal?';
        $directory = fn () => $this->getJson(self::URL.'/offline-sites')->assertOk();
        $this->getJson($url)->assertForbidden();
        $pair = function () {
            $code = $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
            $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone'])->assertCreated();
        };
        $pair();
        $version = $directory()->assertJsonPath('data.journalSiteIds', [$site->id])->json('data.version');
        $pull = fn (?string $cursor = null, ?string $scope = null) => $this->getJson($url.http_build_query([
            'version' => $scope ?? $version, 'cursor' => $cursor,
        ]));

        $initial = $pull()->assertOk()->assertJsonCount(1, 'data.changes')
            ->assertJsonPath('data.changes.0.title', 'Before download')->assertJsonPath('data.hasMore', false);
        $this->assertStringContainsString('no-store', $initial->headers->get('Cache-Control'));
        $cursor = $initial->json('data.cursor');

        $latest = $note($site, 'New note');
        $journal->save($author, ['version' => 1, 'title' => 'Corrected note'], $latest);
        $journal->save($author, ['version' => 1, 'category' => 'observation'], $existing);
        $note($private, 'Another private note');
        $updated = $pull($cursor)->assertOk()->assertJsonCount(2, 'data.changes')
            ->assertJsonPath('data.changes.0.title', 'Corrected note')
            ->assertJsonPath('data.changes.1', ['id' => $existing->id, 'type' => 'remove']);
        $cursor = $updated->json('data.cursor');
        $pull($cursor)->assertOk()->assertJsonPath('data.changes', []);
        $pull('tampered')->assertConflict();

        $pair();
        $pull($cursor)->assertConflict();
        $cursor = $pull()->assertOk()->json('data.cursor');
        for ($index = 0; $index < 51; $index++) {
            $note($site, 'Paged note '.$index);
        }
        $page = $pull($cursor)->assertOk()->assertJsonCount(50, 'data.changes')->assertJsonPath('data.hasMore', true);
        $last = $pull($page->json('data.cursor'))->assertOk()->assertJsonCount(1, 'data.changes')
            ->assertJsonPath('data.changes.0.title', 'Paged note 50')->assertJsonPath('data.hasMore', false);
        $cursor = $last->json('data.cursor');
        $member->forceFill(['location_access' => [['type' => 'room', 'id' => $room->id]]])->save();
        $this->actingAs($member->refresh());
        $roomVersion = $directory()->assertJsonCount(1, 'data.sites')->assertJsonPath('data.journalSiteIds', [])->json('data.version');
        $pull($cursor)->assertConflict();
        $pull($cursor, $roomVersion)->assertConflict();
        $pull(null, $roomVersion)->assertOk()->assertJsonPath('data.changes', []);
    }

    public function test_offline_file_selection_is_scoped_to_access_device_and_version(): void
    {
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
        $site = Site::create(['name' => 'File site']);
        $private = Site::create(['name' => 'Private file site']);
        $uploader = User::factory()->create();
        $library = app(DocumentLibrary::class);
        $upload = fn (Site $location, string $title) => $library->upload($uploader, [
            'title' => $title, 'subject' => ['type' => 'site', 'id' => $location->id],
        ], UploadedFile::fake()->createWithContent('example.txt', 'Private fixture bytes'));
        $file = $upload($site, 'Shared fixture');
        $hidden = $upload($private, 'Private fixture');
        $member = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $this->actingAs($member);
        $url = self::URL.'/offline-files';
        $this->getJson($url)->assertForbidden();
        $pair = function () {
            $code = $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
            $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone'])->assertCreated();
        };
        $pair();
        $this->getJson($url.'?search=shared')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $file->id);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($url.'/'.$hidden->id)->assertNotFound();
        $this->postJson($url.'/selection', ['ids' => [$file->id, $hidden->id]])->assertConflict();
        $this->postJson($url.'/selection', ['ids' => array_fill(0, 5, $file->id)])->assertUnprocessable();

        $response = $this->postJson($url.'/selection', ['ids' => [$file->id]])->assertOk()->assertJsonPath('data.files.0.sha256', $file->sha256);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $ticket = $response->json('data.ticket');
        $this->postJson($url.'/selection', ['ticket' => $ticket])->assertOk()->assertJsonPath('data.valid', true);
        $download = $this->get($url.'/'.$file->id)->assertOk();
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->assertSame('Private fixture bytes', $download->streamedContent());

        $pair();
        $this->postJson($url.'/selection', ['ticket' => $ticket])->assertConflict();
        $ticket = $this->postJson($url.'/selection', ['ids' => [$file->id]])->assertOk()->json('data.ticket');
        $file->forceFill(['version' => $file->version + 1])->save();
        $this->postJson($url.'/selection', ['ticket' => $ticket])->assertConflict();
        $ticket = $this->postJson($url.'/selection', ['ids' => [$file->id]])->assertOk()->json('data.ticket');
        $member->forceFill(['location_access' => []])->save();
        $this->actingAs($member->refresh());
        $this->postJson($url.'/selection', ['ticket' => $ticket])->assertConflict();
        $this->getJson($url.'/'.$file->id)->assertNotFound();
    }

    public function test_offline_plants_exclude_unauthorized_locations_and_detect_a_move(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Shared site']);
        $allowed = $site->rooms()->create(['name' => 'Allowed room']);
        $zone = $allowed->zones()->create(['name' => 'Allowed zone']);
        $private = $site->rooms()->create(['name' => 'Private room']);
        $strain = Strain::create(['name' => 'Offline strain', 'notes' => 'Not part of the plant summary']);
        $tracking = app(PlantTracking::class);
        $create = fn (string $kind, string $id) => $tracking->create($admin, [
            'strain_id' => $strain->id, 'request_id' => (string) Str::uuid(), 'location' => ['type' => $kind, 'id' => $id],
        ]);
        $visible = $create('zone', $zone->id);
        $hidden = $create('room', $private->id);
        $member = User::factory()->create(['location_access' => [['type' => 'room', 'id' => $allowed->id]]]);
        $this->actingAs($member);
        $url = self::URL.'/offline-plants';
        $this->getJson($url)->assertForbidden();
        $code = $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
        $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone'])->assertCreated();
        $directory = self::URL.'/offline-sites';
        $version = $this->getJson($directory)->assertOk()->json('data.version');
        $this->getJson($url.'?version='.str_repeat('0', 64))->assertConflict();
        $url .= '?version='.$version;
        $catalog = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.plants')->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.plants.0.id', $visible->id)->assertJsonPath('data.plants.0.strain', $strain->name)
            ->assertJsonPath('data.plants.0.location', [$site->name, $allowed->name, $zone->name]);
        $this->assertStringContainsString('no-store', $catalog->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($hidden->id, $catalog->getContent());
        $this->assertStringNotContainsString($strain->notes, $catalog->getContent());
        $fingerprint = $catalog->json('data.version');
        $this->getJson($url.'&check=1')->assertExactJson(['data' => ['version' => $fingerprint]]);

        $visible->forceFill(['zone_id' => null, 'room_id' => $private->id])->save();
        $this->getJson($directory)->assertJsonPath('data.version', $version);
        $changed = $this->getJson($url.'&check=1')->assertOk();
        $this->assertNotSame($fingerprint, $changed->json('data.version'));
        $this->getJson($url)->assertJsonPath('data.plants', []);
    }

    public function test_mobile_maintenance_is_paired_idempotent_and_does_not_overwrite_a_newer_version(): void
    {
        $grower = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Maintenance fixture']);
        $strain = Strain::create(['name' => 'Maintenance strain']);
        $tracking = app(PlantTracking::class);
        $plant = $tracking->create($grower, [
            'strain_id' => $strain->id, 'planned' => true, 'request_id' => (string) Str::uuid(), 'location' => ['type' => 'site', 'id' => $site->id],
        ]);
        foreach (['sow', 'plant'] as $action) {
            $plant = $tracking->action($grower, $plant, ['action' => $action, 'version' => $plant->version, 'request_id' => (string) Str::uuid()]);
        }

        $this->actingAs($grower);
        $url = self::URL.'/offline-maintenance/'.$plant->id;
        $input = [
            'version' => $plant->version, 'request_id' => (string) Str::uuid(), 'type' => 'Inspection',
            'comment' => 'Offline action', 'occurred_at' => now()->toISOString(), 'account_id' => $grower->uuid,
            'device_id' => (string) Str::uuid(),
        ];
        $this->postJson($url, $input)->assertForbidden();
        $code = $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
        $device = $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone'])->assertCreated()->json('data.id');
        $this->postJson($url, $input)->assertForbidden();
        $input['device_id'] = $device;
        $this->postJson($url, [...$input, 'document_ids' => [(string) Str::uuid()]])->assertUnprocessable();
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $plant->id);
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $plant->id);
        $this->postJson($url, [...$input, 'request_id' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('error.code', 'plants.errors.conflict');
        $this->postJson($url, [...$input, 'type' => 'Changed action'])
            ->assertConflict()->assertJsonPath('error.code', 'plants.errors.requestConflict');
        $this->assertSame(1, $plant->events()->where('kind', 'maintained')->count());
        $this->assertSame($input['version'] + 1, $plant->fresh()->version);
        $this->deleteJson(self::URL.'/'.$device)->assertNoContent();
        $this->postJson($url, $input)->assertForbidden();
    }

    public function test_mobile_attachment_retries_keep_one_private_file_and_link_to_its_plant(): void
    {
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
        $site = Site::create(['name' => 'Attachment site']);
        $strain = Strain::create(['name' => 'Attachment strain']);
        $user = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('grower')->id]]);
        $tracking = app(PlantTracking::class);
        $create = fn () => $tracking->create($user, [
            'strain_id' => $strain->id, 'request_id' => (string) Str::uuid(), 'location' => ['type' => 'site', 'id' => $site->id],
        ]);
        $plant = $create();
        $plant = $tracking->action($user, $plant, ['action' => 'plant', 'version' => $plant->version, 'request_id' => (string) Str::uuid()]);
        $other = $create();
        $this->actingAs($user);
        $url = self::URL.'/offline-attachments/'.$plant->id;
        $binding = ['account_id' => $user->uuid, 'device_id' => (string) Str::uuid()];
        $upload = ['upload_id' => (string) Str::uuid(), 'version' => $plant->version];
        $file = fn (string $content = 'Offline inspection note') => UploadedFile::fake()->createWithContent('Inspection.txt', $content);
        $headers = ['Accept' => 'application/json'];
        $this->post($url, [...$binding, ...$upload, 'file' => $file()], $headers)->assertForbidden();
        $code = $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
        $binding['device_id'] = $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone'])->assertCreated()->json('data.id');

        $send = fn (array $data = [], ?UploadedFile $attachment = null) => $this->post($url, [
            ...$binding, ...$upload, 'file' => $attachment ?? $file(), ...$data,
        ], $headers);
        $send(['version' => 1])->assertConflict();
        $send([], UploadedFile::fake()->createWithContent('invalid.txt', str_repeat('x', 2 * 1024 * 1024 + 1)))->assertUnprocessable();
        $this->assertDatabaseCount('documents', 0);
        $document = $send()->assertOk()->json('data.id');
        $send()->assertOk()->assertJsonPath('data.id', $document);
        $send([], $file('changed file'))->assertConflict()->assertJsonPath('error.code', 'offlinePlants.attachmentConflict');
        $this->assertDatabaseHas('documents', ['id' => $document, 'site_id' => $site->id, 'uploaded_by' => $user->id]);
        $this->assertSame([$document.'/original.txt'], Storage::disk('documents')->allFiles());

        $maintenance = [...$binding, 'version' => $plant->version, 'request_id' => (string) Str::uuid(), 'type' => 'Inspection',
            'occurred_at' => now()->toISOString(), 'attachment_id' => $upload['upload_id']];
        $action = self::URL.'/offline-maintenance/';
        $this->postJson($action.$other->id, [...$maintenance, 'version' => $other->version])->assertUnprocessable();
        $this->postJson($action.$plant->id, $maintenance)->assertOk();
        $send()->assertOk()->assertJsonPath('data.id', $document);
        $this->postJson($action.$plant->id, $maintenance)->assertOk();
        $event = $plant->events()->where('kind', 'maintained')->sole();
        $this->assertSame([$document], $event->documents()->pluck('documents.id')->all());
        $this->assertDatabaseCount('mobile_attachment_receipts', 1);
        $this->deleteJson(self::URL.'/'.$binding['device_id'])->assertNoContent();
        $send()->assertForbidden();
    }

    public function test_offline_gallery_photo_is_private_and_replayed_without_changing_the_plant_version(): void
    {
        Storage::fake('documents');
        config(['documents.disk' => 'documents']);
        $site = Site::create(['name' => 'Photo site']);
        $strain = Strain::create(['name' => 'Photo strain']);
        $user = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('grower')->id]]);
        $plant = app(PlantTracking::class)->create($user, [
            'strain_id' => $strain->id, 'request_id' => (string) Str::uuid(), 'location' => ['type' => 'site', 'id' => $site->id],
        ]);
        $this->actingAs($user);
        $code = $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
        $device = $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone'])->assertCreated()->json('data.id');
        $version = $this->getJson(self::URL.'/offline-sites')->assertOk()->json('data.version');
        $this->getJson(self::URL.'/offline-plants?version='.$version)->assertOk()->assertJsonPath('data.plants.0.canPhotograph', true);

        $input = ['account_id' => $user->uuid, 'device_id' => $device, 'upload_id' => (string) Str::uuid(), 'version' => $plant->version, 'gallery' => '1'];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0wAAAABJRU5ErkJggg==', true);
        $send = fn (array $changes = [], ?UploadedFile $photo = null) => $this->post(self::URL.'/offline-attachments/'.$plant->id, [
            ...$input, ...$changes, 'file' => $photo ?? UploadedFile::fake()->createWithContent('gallery.png', $png),
        ], ['Accept' => 'application/json']);

        $send(['version' => $plant->version + 1])->assertConflict();
        $send([], UploadedFile::fake()->createWithContent('not-a-photo.png', 'plain text'))->assertUnprocessable();
        $this->assertDatabaseCount('documents', 0);
        $document = $send()->assertOk()->assertJsonPath('data.gallery', true)->json('data.id');
        $send()->assertOk()->assertJsonPath('data.id', $document);
        $send(['gallery' => '0'])->assertConflict();
        $this->assertDatabaseCount('plant_photos', 1);
        $this->assertDatabaseHas('plant_photos', ['plant_id' => $plant->id, 'document_id' => $document]);
        $this->assertDatabaseHas('documents', ['id' => $document, 'site_id' => $site->id, 'uploaded_by' => $user->id]);
        $this->assertSame($plant->version, $plant->fresh()->version);
        $this->assertNull($plant->fresh()->photo_document_id);
        $this->deleteJson(self::URL.'/'.$device)->assertNoContent();
        $send()->assertForbidden();
    }

    public function test_offline_creation_respects_location_and_seed_stock_and_replays_the_same_plant(): void
    {
        $site = Site::create(['name' => 'Creation site']);
        $room = $site->rooms()->create(['name' => 'Creation room']);
        $zone = $room->zones()->create(['name' => 'Creation zone']);
        $strain = Strain::create(['name' => 'Available strain']);
        Strain::create(['name' => 'Archived strain', 'is_archived' => true]);
        $stock = InventoryItem::create(['name' => 'Depleted seed stock', 'type' => 'seeds', 'unit' => 'piece']);
        $depleted = Strain::create(['name' => 'Out of stock', 'seed_inventory_item_id' => $stock->id]);
        $user = User::factory()->create([
            'permission_role_ids' => [AccessProfileSeeder::defaultProfile('grower')->id],
            'location_access' => [['type' => 'zone', 'id' => $zone->id]],
        ]);
        $this->actingAs($user);
        $url = self::URL.'/offline-plants';
        $input = [
            'request_id' => (string) Str::uuid(), 'account_id' => $user->uuid, 'device_id' => (string) Str::uuid(),
            'name' => 'Created offline', 'planned' => true, 'strain_id' => $strain->id, 'location' => ['type' => 'zone', 'id' => $zone->id],
        ];
        $this->postJson($url, $input)->assertForbidden();
        $code = $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
        $input['device_id'] = $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone'])->assertCreated()->json('data.id');
        $version = $this->getJson(self::URL.'/offline-sites')->assertOk()->json('data.version');
        $catalog = $this->getJson($url.'?version='.$version)->assertOk();
        $catalog->assertJsonPath('data.creation.locations', [
            ['type' => 'zone', 'id' => $zone->id, 'name' => 'Creation site › Creation room › Creation zone'],
        ])->assertJsonCount(2, 'data.creation.strains')->assertJsonMissing(['name' => 'Archived strain']);
        $this->postJson($url, [...$input, 'planned' => false])->assertUnprocessable();
        $this->postJson($url, [...$input, 'location' => ['type' => 'room', 'id' => $room->id]])->assertForbidden();
        $this->postJson($url, [...$input, 'strain_id' => $depleted->id])->assertUnprocessable()
            ->assertJsonPath('error.code', 'plants.errors.seedStockEmpty');
        $plant = $this->postJson($url, $input)->assertOk()->json('data.id');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $plant);
        $this->postJson($url, [...$input, 'name' => 'Changed payload'])->assertConflict();
        $this->assertDatabaseHas('plants', ['id' => $plant, 'phase' => 'planned', 'started_at' => null, 'zone_id' => $zone->id]);
        $this->assertDatabaseCount('plants', 1);
        $this->assertDatabaseCount('plant_events', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_offline_plain_watering_validates_version_and_never_uses_inventory_or_pump(): void
    {
        $site = Site::create(['name' => 'Watering site']);
        $strain = Strain::create(['name' => 'Watering strain']);
        $user = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('grower')->id]]);
        $tracking = app(PlantTracking::class);
        $plant = $tracking->create($user, [
            'strain_id' => $strain->id, 'request_id' => (string) Str::uuid(), 'location' => ['type' => 'site', 'id' => $site->id],
        ]);
        $plant = $tracking->action($user, $plant, ['action' => 'plant', 'version' => $plant->version, 'request_id' => (string) Str::uuid()]);

        $this->actingAs($user);
        $code = $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
        $device = $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone'])->assertCreated()->json('data.id');
        $url = self::URL.'/offline-watering/'.$plant->id;
        $input = [
            'version' => $plant->version, 'request_id' => (string) Str::uuid(), 'with_products' => false,
            'water_liters' => '1.250', 'occurred_at' => now()->addMinutes(2)->toISOString(), 'account_id' => $user->uuid, 'device_id' => $device,
        ];
        $this->postJson($url, [...$input, 'with_products' => true])->assertUnprocessable();
        $this->postJson($url, [...$input, 'device_id' => (string) Str::uuid()])->assertForbidden();
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonPath('error.code', 'plants.errors.timeInvalid');
        $this->assertSame($plant->version, $plant->fresh()->version);
        $this->assertFalse($plant->events()->where('kind', 'watered')->exists());

        $this->travel(3)->minutes();
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $plant->id);
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, [...$input, 'request_id' => (string) Str::uuid()])->assertConflict()
            ->assertJsonPath('error.code', 'plants.errors.conflict');

        $event = Plant::findOrFail($plant->id)->events()->where('kind', 'watered')->sole();
        $this->assertSame('1.250', $event->snapshot['watering']['water_liters']);
        $this->assertFalse($event->snapshot['watering']['with_products']);
        $this->assertNull($event->snapshot['watering']['pump']);
        $this->assertDatabaseCount('pump_activations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_replacement_expiry_and_session_revocation_invalidate_pending_codes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $issue = fn () => $this->postJson(self::URL.'/pairing')->assertCreated()->json('data.code');
        $redeem = fn (string $code) => $this->postJson(self::URL.'/redeem', ['code' => $code, 'label' => 'Phone']);
        $replaced = $issue();
        $expired = $issue();
        $redeem($replaced)->assertUnprocessable();
        $this->travel(6)->minutes();
        $redeem($expired)->assertUnprocessable();
        $revoked = $issue();
        DB::table('users')->where('id', $user->id)->increment('session_version');
        $this->actingAs($user->refresh())->withSession(['auth_version' => 1]);
        $redeem($revoked)->assertUnprocessable();
    }
}
