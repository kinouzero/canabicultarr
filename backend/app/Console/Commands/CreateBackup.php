<?php

namespace App\Console\Commands;

use App\Exceptions\BackupException;
use App\Services\InstanceBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateBackup extends Command
{
    protected $signature = 'backup:create';

    protected $description = 'Create a private, encrypted backup of the instance';

    public function handle(InstanceBackup $backup): int
    {
        try {
            $name = $backup->create();
        } catch (Throwable $error) {
            // Third-party errors can contain credentials and signed storage URLs.
            $reason = $error instanceof BackupException
                ? $error->getMessage() : 'The backup could not be completed. Check database and storage access.';
            Log::error('backup.failed', ['reason' => $reason]);
            $this->error($reason);

            return self::FAILURE;
        }

        Log::info('backup.created', ['archive' => $name]);
        $this->info($name);

        return self::SUCCESS;
    }
}
