<?php

namespace Tests\Feature;

use App\Exceptions\DocumentException;
use App\Models\DocumentFolder;
use App\Models\User;
use App\Services\DocumentFolders;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentFolderConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL row locks.');
        }
    }

    public function test_opposing_moves_cannot_create_a_cycle(): void
    {
        $profile = AccessProfileSeeder::defaultProfile('administrator')->id;
        $users = [User::factory()->create(['permission_role_ids' => [$profile]]), User::factory()->create(['permission_role_ids' => [$profile]])];
        $folders = [];
        foreach (['A', 'B'] as $name) {
            $folders[] = app(DocumentFolders::class)->save($users[0], null, ['name' => $name, 'parent_id' => null]);
        }
        $directory = sys_get_temp_dir().'/folder-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $jobs = [];
        foreach ($folders as $i => $folder) {
            $userId = $users[$i]->id;
            $id = $folder->id;
            $parent = $folders[1 - $i]->id;
            $jobs[] = static function () use ($userId, $id, $parent, $directory, $i) {
                if (! app()->environment('testing') || ! str_contains(DB::connection()->getDatabaseName(), '_test')) {
                    throw new \RuntimeException('Isolated test database required.');
                }
                // Stop both transactions after their initial ancestry snapshot, before acquiring locks.
                $barrier = false;
                DB::listen(function ($query) use (&$barrier, $directory, $i) {
                    if ($barrier || ! str_starts_with($query->sql, 'WITH RECURSIVE ancestors')) {
                        return;
                    }
                    $barrier = true;
                    touch($directory.'/'.$i);
                    $deadline = microtime(true) + 10;
                    while (count(glob($directory.'/*')) < 2) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Folder race barrier timeout.');
                        }
                        usleep(10000);
                    }
                });
                try {
                    app(DocumentFolders::class)->save(User::findOrFail($userId), DocumentFolder::findOrFail($id), ['name' => $i ? 'B' : 'A', 'parent_id' => $parent, 'version' => 1]);

                    return 'saved';
                } catch (DocumentException|ValidationException) {
                    return 'rejected';
                }
            };
        }
        try {
            $results = Concurrency::driver('process')->run($jobs, timeout: 20);
            $this->assertEqualsCanonicalizing(['saved', 'rejected'], $results);
            $this->assertSame(1, DocumentFolder::whereNull('parent_id')->count());
            foreach ($folders as $folder) {
                $this->assertLessThanOrEqual(2, count(app(DocumentFolders::class)->ancestors([$folder->id])));
            }
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }

    public function test_competing_creates_and_parent_deletion_preserve_uniqueness_and_references(): void
    {
        $profile = AccessProfileSeeder::defaultProfile('administrator')->id;
        $users = [User::factory()->create(['permission_role_ids' => [$profile]]), User::factory()->create(['permission_role_ids' => [$profile]])];
        foreach (['duplicate', 'root-duplicate', 'delete'] as $mode) {
            $parent = app(DocumentFolders::class)->save($users[0], null, ['name' => $mode, 'parent_id' => null]);
            $parentId = $parent->id;
            $directory = sys_get_temp_dir().'/folder-create-race-'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $jobs = [];
            foreach ($users as $i => $user) {
                $userId = $user->id;
                $jobs[] = static function () use ($mode, $parentId, $userId, $directory, $i) {
                    if (! app()->environment('testing') || ! str_contains(DB::connection()->getDatabaseName(), '_test')) {
                        throw new \RuntimeException('Isolated test database required.');
                    }
                    touch($directory.'/'.$i);
                    $deadline = microtime(true) + 10;
                    while (count(glob($directory.'/*')) < 2) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Create race barrier timeout.');
                        }
                        usleep(10000);
                    }
                    try {
                        $actor = User::findOrFail($userId);
                        if ($mode === 'delete' && $i === 0) {
                            app(DocumentFolders::class)->delete($actor, DocumentFolder::findOrFail($parentId), 1);
                        } else {
                            app(DocumentFolders::class)->save($actor, null, ['name' => 'Same child', 'parent_id' => $mode === 'root-duplicate' ? null : $parentId]);
                        }

                        return 'saved';
                    } catch (DocumentException|ValidationException) {
                        return 'rejected';
                    }
                };
            }
            try {
                $this->assertEqualsCanonicalizing(['saved', 'rejected'], Concurrency::driver('process')->run($jobs, timeout: 20));
                $children = DocumentFolder::where('parent_id', $parentId)->count();
                if ($mode === 'root-duplicate') {
                    $this->assertSame(1, DocumentFolder::whereNull('parent_id')->where('name_key', 'same child')->count());
                } elseif ($mode === 'duplicate') {
                    $this->assertSame(1, $children);
                } else {
                    $this->assertSame(DocumentFolder::whereKey($parentId)->exists() ? 1 : 0, $children);
                }
            } finally {
                foreach (glob($directory.'/*') as $file) {
                    unlink($file);
                } rmdir($directory);
            }
        }
    }

    public function test_leaf_writes_do_not_wait_for_a_locked_ancestor(): void
    {
        $profile = AccessProfileSeeder::defaultProfile('administrator')->id;
        $user = User::factory()->create(['permission_role_ids' => [$profile]]);
        $first = app(DocumentFolders::class)->save($user, null, ['name' => 'First', 'parent_id' => null]);
        $second = app(DocumentFolders::class)->save($user, null, ['name' => 'Second', 'parent_id' => $first->id]);
        $directory = sys_get_temp_dir().'/folder-lock-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $firstId = $first->id;
        $secondId = $second->id;
        $userId = $user->id;
        try {
            $results = Concurrency::driver('process')->run([
                static function () use ($firstId, $directory) {
                    if (! app()->environment('testing') || ! str_contains(DB::connection()->getDatabaseName(), '_test')) {
                        throw new \RuntimeException('Isolated test database required.');
                    }

                    return DB::transaction(function () use ($firstId, $directory) {
                        DocumentFolder::whereKey($firstId)->lockForUpdate()->firstOrFail();
                        touch($directory.'/locked');
                        $deadline = microtime(true) + 10;
                        while (! file_exists($directory.'/saved')) {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('Independent write blocked.');
                            } usleep(10000);
                        }

                        return 'released';
                    });
                },
                static function () use ($secondId, $userId, $directory) {
                    if (! app()->environment('testing') || ! str_contains(DB::connection()->getDatabaseName(), '_test')) {
                        throw new \RuntimeException('Isolated test database required.');
                    }
                    $deadline = microtime(true) + 10;
                    while (! file_exists($directory.'/locked')) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Lock barrier timeout.');
                        } usleep(10000);
                    }
                    DB::statement("SET lock_timeout = '500ms'");
                    $baselineStart = microtime(true);
                    $baselineBlocked = false;
                    try {
                        DB::transaction(fn () => DocumentFolder::orderBy('id')->lockForUpdate()->get());
                    } catch (QueryException $error) {
                        if (($error->errorInfo[0] ?? null) !== '55P03') {
                            throw $error;
                        }
                        $baselineBlocked = true;
                    }
                    if (! $baselineBlocked) {
                        throw new \RuntimeException('Expected the full-tree lock control to block.');
                    }
                    $baselineWaitMilliseconds = round((microtime(true) - $baselineStart) * 1000, 2);
                    $branchStart = microtime(true);
                    app(DocumentFolders::class)->save(User::findOrFail($userId), null, ['name' => 'Independent child', 'parent_id' => $secondId]);
                    $branchWriteMilliseconds = round((microtime(true) - $branchStart) * 1000, 2);
                    file_put_contents('/tmp/cana53-lock-benchmark.json', json_encode(compact('baselineWaitMilliseconds', 'branchWriteMilliseconds', 'baselineBlocked')));
                    touch($directory.'/saved');

                    return 'saved';
                },
            ], timeout: 20);
            $this->assertSame(['released', 'saved'], $results);
            $this->assertSame(1, DocumentFolder::where('parent_id', $secondId)->count());
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }
}
