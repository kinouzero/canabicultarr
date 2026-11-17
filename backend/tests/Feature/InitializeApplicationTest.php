<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\CacheCommandMutex;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Mockery;
use PDOException;
use Tests\TestCase;

class InitializeApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // This command must create its own schema, independent of RefreshDatabase.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
    }

    public function test_empty_database_is_initialized_and_repeated_startup_preserves_records(): void
    {
        $this->assertFalse(Schema::hasTable('pump_activations'));
        $this->artisan('app:initialize', ['--attempts' => 1])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('pump_activations'));
        $user = User::factory()->create();
        $migrations = DB::table('migrations')->count();

        $this->artisan('app:initialize', ['--attempts' => 1])->assertSuccessful();
        $this->assertSame($migrations, DB::table('migrations')->count());
        $this->assertSame($user->id, User::sole()->id);
    }

    public function test_unavailable_database_stops_after_the_configured_attempts(): void
    {
        Sleep::fake();
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')->twice()->andThrow(new PDOException('Unavailable'));
        DB::shouldReceive('connection')->twice()->andReturn($connection);
        DB::shouldReceive('purge')->twice();
        $this->artisan('app:initialize', ['--attempts' => 2])->assertFailed();
        Sleep::assertSleptTimes(1);
    }

    public function test_a_migration_failure_is_not_retried_or_reported_as_success(): void
    {
        $migration = $this->migrationReturning([1]);
        $this->artisan('app:initialize', ['--attempts' => 2])->assertFailed();
        $this->assertSame(1, $migration->calls);
    }

    public function test_another_migrator_is_waited_for_before_initialization_completes(): void
    {
        Sleep::fake();
        $migration = $this->migrationReturning([75, 0]);
        $this->artisan('app:initialize', ['--attempts' => 2])->assertSuccessful();
        $this->assertSame(2, $migration->calls);
        Sleep::assertSleptTimes(1);
    }

    public function test_an_unavailable_migration_lock_cannot_release_application_services(): void
    {
        Sleep::fake();
        $migration = $this->migrationReturning([75, 75]);
        $this->artisan('app:initialize', ['--attempts' => 2])->assertFailed();
        $this->assertSame(2, $migration->calls);
    }

    public function test_native_migration_lock_blocks_boot_until_the_other_migrator_finishes(): void
    {
        $migration = $this->app->make(Kernel::class)->all()['migrate'];
        $mutex = $this->app->make(CacheCommandMutex::class);
        $this->assertTrue($mutex->create($migration));
        try {
            $this->artisan('app:initialize', ['--attempts' => 1])->assertFailed();
            $this->assertFalse(Schema::hasTable('pump_activations'));
            $this->assertTrue($mutex->exists($migration));
        } finally {
            $mutex->forget($migration);
        }
        $this->artisan('app:initialize', ['--attempts' => 1])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('pump_activations'));
    }

    public function test_first_boot_can_create_database_backed_cache_tables(): void
    {
        $directory = sys_get_temp_dir().'/canabicultarr-migration-lock-'.bin2hex(random_bytes(8));
        config(['cache.default' => 'database', 'cache.stores.file.path' => $directory, 'cache.stores.file.lock_path' => $directory]);
        try {
            $this->artisan('app:initialize', ['--attempts' => 1])->assertSuccessful();
            $this->assertTrue(Schema::hasTable('cache'));
            $this->assertTrue(Schema::hasTable('pump_activations'));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_invalid_attempt_limits_are_rejected(): void
    {
        foreach (['0', '151', 'bad'] as $attempts) {
            $this->artisan('app:initialize', ['--attempts' => $attempts])->assertExitCode(2);
        }
    }

    private function migrationReturning(array $statuses): Command
    {
        $command = new class($statuses) extends Command
        {
            protected $signature = 'migrate {--force} {--isolated=}';

            public int $calls = 0;

            public function __construct(private array $statuses)
            {
                parent::__construct();
            }

            public function handle(): int
            {
                return $this->statuses[$this->calls++];
            }
        };
        $this->app->make(Kernel::class)->registerCommand($command);

        return $command;
    }
}
