<?php

namespace Tests\Feature;

use App\Services\InstanceBackup;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstanceBackupTest extends TestCase
{
    public function test_schedule_accepts_daily_and_weekly_settings_and_rejects_invalid_values(): void
    {
        config(['backup.frequency' => 'daily', 'backup.time' => '02:30', 'backup.timezone' => 'Europe/Paris']);
        $this->assertTrue(InstanceBackup::scheduleIsValid());

        config(['backup.frequency' => 'weekly', 'backup.day' => '6']);
        $this->assertTrue(InstanceBackup::scheduleIsValid());

        config(['backup.day' => '7']);
        $this->assertFalse(InstanceBackup::scheduleIsValid());

        config(['backup.day' => '0', 'backup.time' => '25:30']);
        $this->assertFalse(InstanceBackup::scheduleIsValid());

        config(['backup.time' => '02:30', 'backup.timezone' => 'invalid']);
        $this->assertFalse(InstanceBackup::scheduleIsValid());
    }

    public function test_no_archive_is_created_without_a_public_encryption_key(): void
    {
        $path = sys_get_temp_dir().'/backup-test-'.bin2hex(random_bytes(8));
        config(['backup.path' => $path, 'backup.recipient' => 'invalid-key']);

        $this->artisan('backup:create')->expectsOutputToContain('BACKUP_RECIPIENT')->assertFailed();
        $this->assertDirectoryDoesNotExist($path);
    }

    public function test_an_existing_backup_lock_is_respected(): void
    {
        $path = sys_get_temp_dir().'/backup-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($path, 0700);
        $lock = fopen($path.'/.backup.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX));
        config(['backup.path' => $path, 'backup.recipient' => 'age1'.str_repeat('a', 58)]);

        try {
            $this->artisan('backup:create')->expectsOutputToContain('Another backup')->assertFailed();
            $this->assertCount(0, File::files($path));
        } finally {
            fclose($lock);
            File::deleteDirectory($path);
        }
    }
}
