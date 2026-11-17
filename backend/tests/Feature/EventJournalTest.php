<?php

namespace Tests\Feature;

use App\Models\EventEntry;
use App\Models\MeasurementType;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use App\Services\AssetAdministration;
use App\Services\EventJournal;
use App\Services\SensorAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function payload(array $fields = []): array
    {
        return ['title' => 'Test Observation', 'body' => 'Test operational notes', 'category' => 'observation', 'severity' => 'info', 'occurred_at' => '2026-11-06T14:30:15.123456+01:00', ...$fields];
    }

    private function entry(User $actor, array $fields = []): EventEntry
    {
        return app(EventJournal::class)->save($actor, $this->payload($fields));
    }

    private function graph(): array
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Original Site']);
        $room = new Room(['name' => 'Room']);
        $room->site()->associate($site);
        $room->save();
        $zone = new Zone(['name' => 'Zone']);
        $zone->room()->associate($room);
        $zone->save();
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Original Device', 'locations' => [['type' => 'zone', 'id' => $zone->id]]]);
        $type = MeasurementType::create(['name' => 'Type', 'code' => 'type']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => 'Probe', 'channel_key' => 'probe', 'device_id' => $asset->id, 'measurement_type_id' => $type->id]);

        return [$admin, $site, $room, $zone, $asset, $sensor];
    }

    public function test_guests_cannot_read_or_write_the_journal(): void
    {
        $entry = $this->entry(User::factory()->create());
        $this->getJson('/api/v1/events')->assertUnauthorized();
        $this->postJson('/api/v1/events', $this->payload())->assertUnauthorized();
        $this->getJson('/api/v1/events/'.$entry->id)->assertUnauthorized();
        $this->patchJson('/api/v1/events/'.$entry->id, ['version' => 1, 'body' => 'Denied'])->assertUnauthorized();
        $this->getJson('/api/v1/events/'.$entry->id.'/revisions')->assertUnauthorized();
    }

    public function test_creation_assigns_uuid_author_and_initial_revision_on_the_server(): void
    {
        $member = User::factory()->create();
        $forged = (string) Str::uuid();
        $data = $this->actingAs($member)->postJson('/api/v1/events', $this->payload(['id' => $forged, 'created_by' => 999, 'author_uuid' => $forged, 'version' => 50, 'site_id' => $forged]))
            ->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.author.id', $member->uuid)
            ->assertJsonPath('data.canEdit', true)->assertJsonPath('data.occurredAt', '2026-11-06T13:30:15.123456Z')
            ->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.author.email')->assertJsonMissingPath('data.search_text')->json('data');
        $this->assertTrue(Str::isUuid($data['id']));
        $this->assertNotSame($forged, $data['id']);
        $this->assertDatabaseHas('event_entries', ['id' => $data['id'], 'created_by' => $member->id, 'site_id' => null]);
        $this->getJson('/api/v1/events/'.$data['id'].'/revisions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.snapshot.title', 'Test Observation');
    }

    public function test_members_correct_own_entries_and_admins_can_correct_others(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $entry = $this->entry($author);
        $this->actingAs($other)->getJson('/api/v1/events/'.$entry->id)->assertOk()->assertJsonPath('data.canEdit', false);
        $this->patchJson('/api/v1/events/'.$entry->id, ['version' => 1, 'body' => 'Denied'])->assertForbidden();
        $this->actingAs($author)->patchJson('/api/v1/events/'.$entry->id, ['version' => 1, 'body' => 'Author correction'])->assertOk()->assertJsonPath('data.version', 2);
        $this->actingAs($admin)->patchJson('/api/v1/events/'.$entry->id, ['version' => 2, 'title' => 'Admin correction', 'created_by' => $admin->id])->assertOk()
            ->assertJsonPath('data.version', 3)->assertJsonPath('data.author.id', $author->uuid);
        $this->getJson('/api/v1/events/'.$entry->id.'/revisions')->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.editor.id', $admin->uuid)->assertJsonPath('data.1.editor.id', $author->uuid)
            ->assertJsonPath('data.2.snapshot.body', 'Test operational notes')->assertJsonMissingPath('data.0.editor.email');
    }

    public function test_stale_versions_do_not_overwrite_or_append_revisions(): void
    {
        $author = User::factory()->create();
        $entry = $this->entry($author);
        $this->actingAs($author);
        $this->patchJson('/api/v1/events/'.$entry->id, ['version' => 1, 'title' => 'Latest'])->assertOk();
        $this->patchJson('/api/v1/events/'.$entry->id, ['version' => 1, 'body' => 'Stale'])->assertConflict()->assertJsonPath('error.code', 'events.errors.conflict');
        $this->assertSame('Latest', $entry->fresh()->title);
        $this->assertDatabaseCount('event_revisions', 2);
        $this->patchJson('/api/v1/events/'.$entry->id, ['body' => 'No version'])->assertUnprocessable();
    }

    public function test_noop_corrections_do_not_change_versions_or_history(): void
    {
        $author = User::factory()->create();
        $entry = $this->entry($author);
        $this->actingAs($author)->patchJson('/api/v1/events/'.$entry->id, [...$this->payload(), 'version' => 1, 'subject' => null])
            ->assertOk()->assertJsonPath('data.version', 1);
        $this->assertDatabaseCount('event_revisions', 1);
    }

    public function test_validation_requires_explicit_valid_zoned_timestamps_and_safe_fields(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['tomorrow', '2026-11-06T14:30:00', '2026-02-30T14:30:00Z', '2026-11-06T25:30:00Z', '2026-11-06T14:30:00+25:99', '2026-11-06T14:30:60Z'] as $time) {
            $this->postJson('/api/v1/events', $this->payload(['occurred_at' => $time]))->assertUnprocessable()->assertJsonPath('errors.occurred_at.0', 'events.validation.timeInvalid');
        }
        foreach ([['title' => ' '], ['title' => str_repeat('x', 161)], ['body' => str_repeat('x', 10001)], ['category' => 'unknown'], ['severity' => 'unknown'],
            ['subject' => []], ['subject' => ['type' => 'user', 'id' => (string) Str::uuid()]], ['subject' => ['type' => 'site', 'id' => 'bad']],
            ['subject' => ['type' => 'site', 'id' => (string) Str::uuid(), 'name' => 'forged']]] as $bad) {
            $this->postJson('/api/v1/events', $this->payload($bad))->assertUnprocessable();
        }
        $this->postJson('/api/v1/events', $this->payload(['subject' => ['type' => 'site', 'id' => (string) Str::uuid()]]))->assertNotFound();
        $this->getJson('/api/v1/events/not-a-uuid')->assertNotFound();
        $this->assertDatabaseCount('event_entries', 0);
        $this->assertDatabaseCount('event_revisions', 0);
    }

    public function test_context_filters_include_descendants_and_sensor_device_entries(): void
    {
        [$admin, $site, $room, $zone, $asset, $sensor] = $this->graph();
        foreach ([['site', $site], ['room', $room], ['zone', $zone], ['asset', $asset], ['sensor', $sensor]] as [$type, $record]) {
            $this->entry($admin, ['title' => 'Context '.$type, 'subject' => ['type' => $type, 'id' => $record->id]]);
        }
        $this->actingAs(User::factory()->create());
        foreach ([['site', $site, 5], ['room', $room, 4], ['zone', $zone, 3], ['asset', $asset, 2], ['sensor', $sensor, 1]] as [$type, $record, $count]) {
            $this->getJson('/api/v1/events?'.$type.'_id='.$record->id)->assertOk()->assertJsonCount($count, 'data');
        }
    }

    public function test_snapshots_keep_original_context_after_device_moves_and_renames(): void
    {
        [$admin, $site, $room, $zone, $asset] = $this->graph();
        $entry = $this->entry($admin, ['subject' => ['type' => 'asset', 'id' => $asset->id]]);
        $snapshot = $entry->subject_snapshot;
        $other = Site::create(['name' => 'New Site']);
        app(AssetAdministration::class)->save($admin, ['name' => 'Renamed Device', 'locations' => [['type' => 'site', 'id' => $other->id]]], $asset);
        $site->name = 'Renamed Original Site';
        $site->save();
        $this->actingAs($admin)->getJson('/api/v1/events/'.$entry->id)->assertJsonPath('data.subject.name', 'Original Device')
            ->assertJsonPath('data.subject.location.site.name', 'Original Site');
        $this->patchJson('/api/v1/events/'.$entry->id, ['version' => 1, 'body' => 'Correction', 'subject' => ['type' => 'asset', 'id' => $asset->id]])->assertOk();
        $this->assertSame($snapshot, $entry->fresh()->subject_snapshot);
        $this->getJson('/api/v1/events?site_id='.$site->id)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/events?site_id='.$other->id)->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/events/'.$entry->id, ['version' => 2, 'subject' => ['type' => 'site', 'id' => $other->id]])->assertOk()->assertJsonPath('data.subject.name', 'New Site');
        $this->assertDatabaseHas('event_entries', ['id' => $entry->id, 'asset_id' => null, 'room_id' => null, 'zone_id' => null, 'site_id' => $other->id]);
    }

    public function test_removing_context_preserves_it_in_previous_revisions(): void
    {
        [$admin, $site] = $this->graph();
        $entry = $this->entry($admin, ['subject' => ['type' => 'site', 'id' => $site->id]]);
        $this->actingAs($admin)->patchJson('/api/v1/events/'.$entry->id, ['version' => 1, 'subject' => null])->assertOk()->assertJsonPath('data.subject', null);
        $this->getJson('/api/v1/events/'.$entry->id.'/revisions')->assertJsonPath('data.0.snapshot.subject', null)->assertJsonPath('data.1.snapshot.subject.id', $site->id);
    }

    public function test_search_dates_and_pagination_are_literal_precise_and_inclusive(): void
    {
        $author = User::factory()->create();
        for ($i = 0; $i < 22; $i++) {
            $this->entry($author, ['title' => 'Entry '.$i, 'occurred_at' => '2026-11-05T12:00:00Z']);
        }
        $entry = $this->entry($author, ['title' => 'Literal _% Étage', 'body' => 'Unique text', 'category' => 'incident', 'severity' => 'critical']);
        $this->actingAs($author)->getJson('/api/v1/events')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23)->assertJsonPath('data.0.id', $entry->id);
        $this->getJson('/api/v1/events?page=2')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/events?search='.urlencode('_% éTAGE'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/events?search=UNIQUE&category=incident&severity=critical')->assertJsonCount(1, 'data');
        $time = urlencode('2026-11-06T13:30:15.123456Z');
        $this->getJson('/api/v1/events?date_from='.$time.'&date_to='.$time)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/events?date_from='.urlencode('2026-11-06T13:30:15.123457Z'))->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/events?date_to='.urlencode('2026-11-05T12:00:00Z'))->assertJsonPath('meta.total', 22);
        $this->getJson('/api/v1/events?date_from='.urlencode('2026-11-07T00:00:00Z').'&date_to='.$time)->assertUnprocessable()->assertJsonPath('errors.date_to.0', 'events.validation.rangeInvalid');
        $this->getJson('/api/v1/events?date_to=tomorrow')->assertUnprocessable();
        $this->getJson('/api/v1/events?page=0')->assertUnprocessable();
    }

    public function test_revision_history_is_scoped_and_paginated(): void
    {
        $author = User::factory()->create();
        $entry = $this->entry($author);
        $other = $this->entry($author, ['title' => 'Other']);
        for ($version = 1; $version <= 22; $version++) {
            app(EventJournal::class)->save($author, ['version' => $version, 'title' => 'Correction '.$version], $entry);
        }
        $this->actingAs($author)->getJson('/api/v1/events/'.$entry->id.'/revisions')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23)->assertJsonPath('data.0.version', 23);
        $this->getJson('/api/v1/events/'.$entry->id.'/revisions?page=2')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/events/'.$other->id.'/revisions')->assertJsonCount(1, 'data');
    }

    public function test_stale_permissions_and_sessions_are_rejected(): void
    {
        $author = User::factory()->create();
        $entry = $this->entry($author);
        $other = User::factory()->create();
        try {
            app(EventJournal::class)->save($other, ['version' => 1, 'title' => 'Denied'], $entry);
            $this->fail('Owner policy must apply in service.');
        } catch (AuthorizationException) {
            $this->assertSame(1, $entry->fresh()->version);
        }
        $stale = clone $author;
        $author->is_active = false;
        $author->save();
        try {
            app(EventJournal::class)->save($stale, $this->payload());
            $this->fail('Inactive actor must be rejected.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('event_entries', 1);
        }
        $author->forceFill(['is_active' => true, 'session_version' => 1])->save();
        $this->actingAs($author)->withSession(['auth_version' => 0])->getJson('/api/v1/events')->assertUnauthorized();
    }

    public function test_foreign_keys_preserve_context_and_revisions_while_deleted_authors_keep_uuid(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Journal-only Site']);
        $entry = $this->entry($admin, ['subject' => ['type' => 'site', 'id' => $site->id]]);
        foreach ([$entry, $site] as $record) {
            try {
                DB::transaction(fn () => $record->delete());
                $this->fail('Referenced record must remain.');
            } catch (QueryException) {
                $this->assertTrue($record->newQuery()->whereKey($record->id)->exists());
            }
        }
        $author = User::factory()->create();
        $orphan = $this->entry($author);
        $uuid = $author->uuid;
        $author->delete();
        $this->actingAs($admin)->getJson('/api/v1/events/'.$orphan->id)->assertJsonPath('data.author.id', $uuid)->assertJsonPath('data.author.name', null);
        $this->getJson('/api/v1/events/'.$orphan->id.'/revisions')->assertJsonPath('data.0.editor.id', $uuid)->assertJsonPath('data.0.editor.name', null);
    }

    public function test_audit_records_identifiers_versions_and_field_names_without_content(): void
    {
        $author = User::factory()->create();
        Log::spy();
        $entry = $this->entry($author);
        Log::shouldHaveReceived('notice')->with('event.created', ['actor_uuid' => $author->uuid, 'event_uuid' => $entry->id, 'version' => 1,
            'fields' => ['title', 'body', 'category', 'severity', 'occurred_at']])->once();
    }
}
