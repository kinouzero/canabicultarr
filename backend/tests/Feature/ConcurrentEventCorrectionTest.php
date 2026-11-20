<?php

namespace Tests\Feature;

use App\Exceptions\EventJournalException;
use App\Models\EventEntry;
use App\Models\Site;
use App\Models\User;
use App\Services\EventJournal;
use App\Services\MobileJournalFeed;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\QueryException;
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

    public function test_mobile_journal_cursor_cannot_advance_past_an_uncommitted_write(): void
    {
        $writer = DB::getDefaultConnection();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent row locking requires PostgreSQL.');
        }

        $site = Site::create(['name' => 'Sync site']);
        [$first, $second] = User::factory()->count(2)->create()->all();
        $feed = app(MobileJournalFeed::class);
        $sites = [$site->id];
        $scope = 'test-scope';
        $cursor = $feed->read($sites, $scope, null)['cursor'];
        $journal = app(EventJournal::class);
        $payload = [
            'title' => 'First write', 'body' => 'Concurrent journal test', 'category' => 'note', 'severity' => 'info',
            'occurred_at' => now()->toISOString(), 'subject' => ['type' => 'site', 'id' => $site->id],
        ];

        config(['database.connections.journal_peer' => config('database.connections.'.$writer)]);
        $connection = DB::connection($writer);
        $connection->beginTransaction();
        try {
            $journal->save($first, $payload);
            DB::setDefaultConnection('journal_peer');
            DB::statement("SET lock_timeout TO '200ms'");
            try {
                $journal->save($second, [...$payload, 'title' => 'Second write']);
                $this->fail('The second writer advanced the cursor while the first was uncommitted.');
            } catch (QueryException $error) {
                $this->assertSame('55P03', (string) $error->getCode());
            }

            $pending = $feed->read($sites, $scope, $cursor);
            $this->assertSame([], $pending['changes']);
            $connection->commit();
            $journal->save($second, [...$payload, 'title' => 'Second write']);

            $page = $feed->read($sites, $scope, $pending['cursor']);
            $this->assertSame(['First write', 'Second write'], array_column($page['changes'], 'title'));
            $this->assertSame([], $feed->read($sites, $scope, $page['cursor'])['changes']);
        } finally {
            $connection->rollBack();
            DB::setDefaultConnection($writer);
            DB::purge('journal_peer');
        }
    }
}
