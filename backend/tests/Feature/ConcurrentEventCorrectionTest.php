<?php

namespace Tests\Feature;

use App\Exceptions\EventJournalException;
use App\Models\EventEntry;
use App\Models\User;
use App\Services\EventJournal;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentEventCorrectionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_corrections_preserve_the_winner_and_reject_stale_versions(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent row locking requires PostgreSQL.');
        }
        $admins = User::factory()->count(2)->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $entry = app(EventJournal::class)->save($admins[0], ['title' => 'Original', 'body' => 'Original notes', 'category' => 'note', 'severity' => 'info', 'occurred_at' => '2026-11-06T12:00:00Z']);
        $eventId = $entry->id;
        $database = DB::connection()->getDatabaseName();
        $operations = [];
        foreach ($admins as $admin) {
            $userId = $admin->id;
            $operations[] = static function () use ($userId, $eventId, $database) {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database) {
                    return 'wrong_test_environment';
                }
                try {
                    app(EventJournal::class)->save(User::findOrFail($userId), ['version' => 1, 'title' => 'Corrected '.$userId], EventEntry::findOrFail($eventId));

                    return 'corrected';
                } catch (EventJournalException $exception) {
                    return $exception->errorCode;
                }
            };
        }
        $results = Concurrency::driver('process')->run($operations, timeout: 20);
        sort($results);
        $this->assertSame(['corrected', 'events.errors.conflict'], $results);
        $this->assertSame(2, $entry->fresh()->version);
        $this->assertSame(2, $entry->revisions()->count());
        $this->assertSame($entry->fresh()->title, $entry->revisions()->where('version', 2)->sole()->snapshot['title']);
    }
}
