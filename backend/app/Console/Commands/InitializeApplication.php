<?php

namespace App\Console\Commands;

use Illuminate\Console\CacheCommandMutex;
use Illuminate\Console\Command;
use Illuminate\Console\CommandMutex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use PDOException;

class InitializeApplication extends Command
{
    protected $signature = 'app:initialize {--attempts=30 : Maximum database and migration-lock attempts}';

    protected $description = 'Wait for the database and apply pending migrations before application services start';

    public function handle(): int
    {
        $attempts = (string) $this->option('attempts');
        if (! ctype_digit($attempts) || (int) $attempts < 1 || (int) $attempts > 150) {
            $this->error('Attempts must be an integer between 1 and 150.');

            return self::INVALID;
        }
        $limit = (int) $attempts;
        for ($attempt = 1; $attempt <= $limit; $attempt++) {
            try {
                DB::connection()->getPdo();
                break;
            } catch (PDOException) {
                DB::purge();
                if ($attempt === $limit) {
                    $this->error('Database unavailable. Check DB_HOST, DB_PORT and database credentials.');

                    return self::FAILURE;
                }
                $this->warn("Waiting for the database ({$attempt}/{$limit}).");
                Sleep::for(2)->seconds();
            }
        }

        if (config('cache.default') === 'database' && ! $this->laravel->bound(CommandMutex::class)) {
            // Database-backed cache tables do not exist until the first migration.
            $mutex = $this->laravel->make(CacheCommandMutex::class);
            $mutex->store = 'file';
            $this->laravel->instance(CommandMutex::class, $mutex);
        }

        for ($attempt = 1; $attempt <= $limit; $attempt++) {
            // A skipped isolated migration must never let workers start against an unfinished schema.
            $status = $this->call('migrate', ['--force' => true, '--no-interaction' => true, '--isolated' => 75]);
            if ($status !== 75) {
                if ($status !== self::SUCCESS) {
                    $this->error('Database migrations failed. Application services were not started.');
                }

                return $status;
            }
            if ($attempt < $limit) {
                $this->warn("Waiting for another instance to finish migrations ({$attempt}/{$limit}).");
                Sleep::for(2)->seconds();
            }
        }
        $this->error('Migration lock remained busy. Application services were not started.');

        return self::FAILURE;
    }
}
