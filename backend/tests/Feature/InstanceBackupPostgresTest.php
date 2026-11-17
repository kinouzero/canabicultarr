<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstanceBackupPostgresTest extends TestCase
{
    use DatabaseMigrations;

    public function test_snapshot_preserves_document_integrity_and_prunes_only_after_success(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL snapshot.');
        }

        $root = sys_get_temp_dir().'/backup-test-'.bin2hex(random_bytes(8));
        $destination = $root.'/archive';
        File::ensureDirectoryExists($destination, 0700);
        File::put($root.'/instance.env', "EXTRA_OPTION=test\n");
        File::put($destination.'/keep.txt', 'unrelated');
        config([
            'backup.path' => $destination,
            'backup.keep' => 1,
            'backup.recipient' => 'age1'.str_repeat('a', 58),
            'backup.config_file' => $root.'/instance.env',
        ]);

        $documents = [];
        foreach (['documents', 'documents_s3'] as $disk) {
            Storage::fake($disk);
            $id = (string) Str::uuid();
            $path = $id.'/original.txt';
            Storage::disk($disk)->put($path, $disk);
            Document::forceCreate([
                'id' => $id, 'title' => $disk, 'original_name' => 'original.txt', 'disk' => $disk, 'path' => $path,
                'sha256' => hash('sha256', $disk), 'size_bytes' => strlen($disk), 'format' => 'text', 'media_type' => 'text/plain', 'uploader_uuid' => $id,
            ]);
            $documents[$disk] = $path;
        }
        $encryptions = 0;
        Process::fake(function (PendingProcess $process) use ($documents, &$encryptions) {
            $command = $process->command;
            if ($command[0] === 'bash') {
                $encryptions++;
                $stage = $command[6];
                foreach ($documents as $disk => $path) {
                    $this->assertSame($disk, File::get($stage.'/files/'.$disk.'/'.$path));
                }
                $this->assertSame(config('app.key'), json_decode(File::get($stage.'/runtime-config.json'), true)['APP_KEY']);
                $this->assertSame(2, json_decode(File::get($stage.'/manifest.json'), true)['documents']);
                $this->assertSame("EXTRA_OPTION=test\n", File::get($stage.'/instance.env'));
                $this->assertSame('database fixture', File::get($stage.'/database.dump'));
                File::put($command[8], 'encrypted fixture');
            } else {
                $this->assertSame('pg_dump', basename($command[0]));
                $this->assertStringStartsWith('--snapshot=', $command[4]);
                $this->assertSame(DB::getDatabaseName(), $process->environment['PGDATABASE']);
                File::put(substr($command[5], strlen('--file=')), 'database fixture');
            }

            return Process::result();
        });

        try {
            $this->artisan('backup:create')->assertSuccessful();
            $first = glob($destination.'/*.tar.age')[0];
            $this->assertSame('encrypted fixture', File::get($first));
            $this->assertSame(0600, fileperms($first) & 0777);
            Storage::disk('documents_s3')->put($documents['documents_s3'], 'changed');
            $this->artisan('backup:create')->expectsOutputToContain('checksum')->assertFailed();
            $this->assertSame([$first], glob($destination.'/*.tar.age'));
            $this->assertSame([], glob($destination.'/*.part'));
            $this->assertSame(1, $encryptions);
            Storage::disk('documents_s3')->put($documents['documents_s3'], 'documents_s3');
            $this->artisan('backup:create')->assertSuccessful();
            $this->assertFileDoesNotExist($first);
            $this->assertFileExists($destination.'/keep.txt');
            $this->assertCount(1, glob($destination.'/*.tar.age'));
            $this->assertSame(2, $encryptions);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
