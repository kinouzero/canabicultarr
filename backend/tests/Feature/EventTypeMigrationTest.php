<?php

namespace Tests\Feature;

use App\Models\EventEntry;
use App\Models\EventRevision;
use App\Models\User;
use App\Services\EventJournal;
use App\Services\EventTypes;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class EventTypeMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        EventRevision::query()->delete();
        EventEntry::query()->delete();
        parent::tearDown();
    }

    public function test_existing_entries_and_revisions_survive_migration_and_round_trip_without_rewrites(): void
    {
        $actor = User::factory()->create();
        $event = app(EventJournal::class)->save($actor, ['title' => 'Existing note', 'body' => 'Original content', 'category' => 'note', 'severity' => 'info', 'occurred_at' => now()->toISOString()]);
        $revision = $event->revisions()->sole()->getRawOriginal();
        $migration = require database_path('migrations/2026_11_15_170000_create_custom_event_types.php');
        $migration->down();
        $before = $event->fresh()->getRawOriginal();
        $migration->up();
        $after = $event->fresh()->getRawOriginal();
        unset($after['event_type_id'], $after['event_type_snapshot']);
        $this->assertSame($before, $after);
        $this->assertSame($revision, $event->revisions()->sole()->getRawOriginal());
    }

    public function test_rollback_refuses_to_discard_custom_type_history_even_after_recategorization(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $type = app(EventTypes::class)->save($admin, ['name' => 'Historical type', 'icon' => 'journal', 'contexts' => ['none']]);
        $event = app(EventJournal::class)->save($admin, ['title' => 'Custom history', 'body' => 'Retain this', 'category' => 'custom', 'event_type_id' => $type->id, 'severity' => 'info', 'occurred_at' => now()->toISOString()]);
        app(EventJournal::class)->save($admin, ['category' => 'note', 'version' => 1], $event);
        $migration = require database_path('migrations/2026_11_15_170000_create_custom_event_types.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
