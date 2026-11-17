<?php

namespace Tests\Feature;

use App\Models\EventEntry;
use App\Models\EventType;
use App\Models\Site;
use App\Models\User;
use App\Services\EventJournal;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventTypesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function type(array $extra = []): array
    {
        return ['name' => 'Custom inspection', 'icon' => 'eye', 'contexts' => ['none', 'site'], 'is_archived' => false, ...$extra];
    }

    private function entry(string $type, array $extra = []): array
    {
        return ['title' => 'Inspection', 'body' => 'Original observation', 'category' => 'custom', 'event_type_id' => $type, 'severity' => 'info', 'occurred_at' => now()->toISOString(), 'subject' => null, ...$extra];
    }

    public function test_only_administrators_manage_the_catalog_and_system_types_cannot_be_modified(): void
    {
        $this->getJson('/api/v1/event-types')->assertUnauthorized();
        $reader = User::factory()->create();
        $this->actingAs($reader)->getJson('/api/v1/event-types')->assertOk()->assertJsonPath('canManage', false);
        $this->postJson('/api/v1/event-types', $this->type())->assertForbidden();
        $this->actingAs($this->admin());
        $type = $this->postJson('/api/v1/event-types', $this->type())->assertCreated()->assertJsonPath('data.version', 1)->json('data');
        $this->patchJson('/api/v1/event-types/note', [...$this->type(), 'version' => 1])->assertNotFound();
        $this->postJson('/api/v1/event-types', $this->type(['name' => ' CUSTOM INSPECTION ']))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/event-types', $this->type(['contexts' => []]))->assertUnprocessable();
        $this->postJson('/api/v1/event-types', $this->type(['icon' => 'arbitrary-network-icon']))->assertUnprocessable();
        $this->patchJson('/api/v1/event-types/'.$type['id'], [...$this->type(['name' => 'Revised inspection']), 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/event-types/'.$type['id'], [...$this->type(['name' => 'Stale write']), 'version' => 1])->assertConflict();
        $this->deleteJson('/api/v1/event-types/'.$type['id'])->assertStatus(405);
        $this->assertSame('Revised inspection', EventType::sole()->name);
    }

    public function test_archival_and_renames_preserve_event_and_revision_type_snapshots(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $type = $this->postJson('/api/v1/event-types', $this->type())->assertCreated()->json('data');
        $reader = User::factory()->create();
        $this->actingAs($reader);
        $id = $this->postJson('/api/v1/events', $this->entry($type['id'], ['event_type_snapshot' => ['name' => 'Forged']]))->assertCreated()->assertJsonPath('data.eventType.name', 'Custom inspection')->json('data.id');
        $initial = EventEntry::findOrFail($id)->revisions()->sole()->getRawOriginal('snapshot');
        $this->actingAs($admin)->patchJson('/api/v1/event-types/'.$type['id'], [...$this->type(['name' => 'New label', 'icon' => 'leaf', 'is_archived' => true]), 'version' => 1])->assertOk();
        $this->actingAs($reader)->patchJson('/api/v1/events/'.$id, ['body' => 'Corrected observation', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.eventType.name', 'Custom inspection')->assertJsonPath('data.eventType.icon', 'eye');
        $this->postJson('/api/v1/events', $this->entry($type['id']))->assertUnprocessable();
        $site = Site::create(['name' => 'New context']);
        $this->patchJson('/api/v1/events/'.$id, ['subject' => ['type' => 'site', 'id' => $site->id], 'version' => 2])->assertUnprocessable();
        $this->patchJson('/api/v1/events/'.$id, ['category' => 'custom', 'event_type_id' => strtoupper($type['id']), 'version' => 2])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/events?event_type_id='.strtoupper($type['id']))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/event-types?state=archived')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/events/'.$id.'/revisions')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.snapshot.eventType.name', 'Custom inspection');
        $this->assertSame($initial, EventEntry::findOrFail($id)->revisions()->where('version', 1)->sole()->getRawOriginal('snapshot'));
    }

    public function test_custom_context_scopes_and_category_pairing_are_enforced_on_the_server(): void
    {
        $this->actingAs($this->admin());
        $id = $this->postJson('/api/v1/event-types', $this->type(['contexts' => ['site']]))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/events', $this->entry($id))->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->postJson('/api/v1/events', $this->entry($id, ['category' => 'note']))->assertUnprocessable()->assertJsonValidationErrors('event_type_id');
        $this->postJson('/api/v1/events', $this->entry($id, ['event_type_id' => null]))->assertUnprocessable();
        $site = Site::create(['name' => 'Inspection site']);
        $entry = $this->postJson('/api/v1/events', $this->entry($id, ['subject' => ['type' => 'site', 'id' => $site->id]]))->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/events/'.$entry, ['category' => 'note', 'event_type_id' => null, 'version' => 1])->assertOk()->assertJsonPath('data.eventType', null);
        $this->assertNull(EventEntry::findOrFail($entry)->event_type_id);
    }

    public function test_database_requires_consistent_custom_type_references(): void
    {
        $actor = $this->admin();
        $entry = app(EventJournal::class)->save($actor, ['title' => 'System note', 'body' => 'Untouched', 'category' => 'note', 'severity' => 'info', 'occurred_at' => now()->toISOString()]);
        $this->expectException(QueryException::class);
        DB::table('event_entries')->where('id', $entry->id)->update(['category' => 'custom']);
    }
}
