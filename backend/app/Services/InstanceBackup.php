<?php

namespace App\Services;

use App\Exceptions\BackupException;
use App\Models\Document;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class InstanceBackup
{
    private const ARCHIVE = '/^canabicultarr-\d{8}T\d{6}Z-[a-f0-9]{12}\.tar\.age$/D';

    public static function scheduleIsValid(): bool
    {
        $frequency = config('backup.frequency');

        return in_array($frequency, ['daily', 'weekly'], true)
            && ($frequency === 'daily' || filter_var(config('backup.day'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]) !== false)
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string) config('backup.time'))
            && in_array(config('backup.timezone'), DateTimeZone::listIdentifiers(), true);
    }

    public function create(): string
    {
        $path = rtrim((string) config('backup.path'), '/');
        $keep = filter_var(config('backup.keep'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $recipient = (string) config('backup.recipient');
        if (! preg_match('/^age1[023456789acdefghjklmnpqrstuvwxyz]{58}$/D', $recipient)) {
            throw new BackupException('Set BACKUP_RECIPIENT to a native age public key.');
        }
        if ($keep === false || ! str_starts_with($path, '/')) {
            throw new BackupException('Set BACKUP_KEEP to a positive integer and BACKUP_PATH to an absolute directory.');
        }

        $mode = umask(0077);
        try {
            File::ensureDirectoryExists($path, 0700);
            $lock = fopen($path.'/.backup.lock', 'c');
            if ($lock === false) {
                throw new BackupException('The backup directory lock could not be opened.');
            }
            try {
                if (! flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new BackupException('Another backup is already running in this directory.');
                }

                return $this->writeArchive($path, $recipient, $keep);
            } finally {
                fclose($lock);
            }
        } finally {
            umask($mode);
        }
    }

    private function writeArchive(string $path, string $recipient, int $keep): string
    {
        $name = 'canabicultarr-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6)).'.tar.age';
        $stage = sys_get_temp_dir().'/'.$name.'.work';
        $partial = $path.'/'.$name.'.part';
        File::ensureDirectoryExists($stage, 0700);
        try {
            $this->writeConfiguration($stage);
            $this->snapshot($stage);
            $this->run([
                'bash', '-o', 'pipefail', '-c', 'tar -C "$1" -cf - . | age --encrypt -r "$2" -o "$3"',
                'backup', $stage, $recipient, $partial,
            ], 'Archive encryption failed. Check age and available disk space.');
            $archive = fopen($partial, 'r+b');
            if ($archive === false) {
                throw new BackupException('The encrypted backup could not be opened.');
            }
            try {
                if (! fsync($archive)) {
                    throw new BackupException('The encrypted backup could not be flushed to storage.');
                }
            } finally {
                fclose($archive);
            }
            if (! rename($partial, $path.'/'.$name)) {
                throw new BackupException('The completed backup could not be published.');
            }
            $this->prune($path, $keep, $name);

            return $name;
        } finally {
            if (is_file($partial)) {
                unlink($partial);
            }
            if (! File::deleteDirectory($stage)) {
                Log::warning('backup.staging_cleanup_failed');
            }
        }
    }

    private function writeConfiguration(string $stage): void
    {
        $template = is_file(resource_path('backup-environment.example'))
            ? resource_path('backup-environment.example') : base_path('../.env.example');
        $content = File::get($template);
        preg_match_all('/^(?:#\s*)?([A-Z][A-Z0-9_]+)=/m', $content, $variables);
        $environment = [];
        foreach (array_unique($variables[1]) as $name) {
            $value = Env::getRepository()->get($name);
            if ($value !== null) {
                $environment[$name] = $value;
            }
        }
        $environment['APP_KEY'] = config('app.key');
        if (! filled($environment['APP_KEY'])) {
            throw new BackupException('The application encryption key is unavailable.');
        }
        $this->writeJson($stage.'/runtime-config.json', $environment);

        $configFile = config('backup.config_file');
        if ($configFile && (! is_file($configFile) || ! is_readable($configFile) || ! File::copy($configFile, $stage.'/instance.env'))) {
            throw new BackupException('The configured BACKUP_CONFIG_FILE is unreadable.');
        }
    }

    private function snapshot(string $stage): void
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'pgsql' || $db->transactionLevel() !== 0) {
            throw new BackupException('Instance backups require a direct PostgreSQL connection.');
        }
        $db->transaction(function (Connection $db) use ($stage) {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $db->unprepared('SET LOCAL idle_in_transaction_session_timeout = 0');
            $info = $db->selectOne("SELECT pg_export_snapshot() AS snapshot, current_setting('server_version') AS version, (SELECT extversion FROM pg_extension WHERE extname = 'timescaledb') AS timescale");
            $this->dump($db, $stage, $info->snapshot);
            $count = 0;
            foreach (Document::on($db->getName())->select(['id', 'disk', 'path', 'sha256'])->lazyById(200) as $document) {
                $this->copyDocument($document, $stage);
                $count++;
            }
            $this->writeJson($stage.'/manifest.json', [
                'format' => 1,
                'created_at' => gmdate(DATE_ATOM),
                'postgresql' => $info->version,
                'timescaledb' => $info->timescale,
                'documents' => $count,
            ]);
        });
    }

    private function dump(Connection $db, string $stage, string $snapshot): void
    {
        $packaged = is_executable('/opt/postgres-tools/bin/pg_dump');
        $this->run([
            $packaged ? '/opt/postgres-tools/bin/pg_dump' : 'pg_dump',
            '--format=custom', '--no-password', '--lock-wait-timeout=30s', '--snapshot='.$snapshot, '--file='.$stage.'/database.dump',
        ], 'PostgreSQL dump failed. Check the connection, client version and available disk space.', [
            ...($packaged ? ['LD_LIBRARY_PATH' => '/opt/postgres-tools/lib'] : []),
            'PGHOST' => (string) $db->getConfig('host'),
            'PGCONNECT_TIMEOUT' => '10',
            'PGPORT' => (string) $db->getConfig('port'),
            'PGDATABASE' => $db->getDatabaseName(),
            'PGUSER' => (string) $db->getConfig('username'),
            'PGPASSWORD' => (string) $db->getConfig('password'),
            'PGSSLMODE' => (string) ($db->getConfig('sslmode') ?? 'prefer'),
        ]);
    }

    private function copyDocument(Document $document, string $stage): void
    {
        if (! in_array($document->disk, ['documents', 'documents_s3'], true)
            || ! preg_match('/^[a-f0-9-]{36}\/original\.[a-z0-9]+$/D', $document->path)) {
            throw new BackupException('A document has an unsupported storage path.');
        }
        $destination = $stage.'/files/'.$document->disk.'/'.$document->path;
        File::ensureDirectoryExists(dirname($destination), 0700);
        $source = Storage::disk($document->disk)->readStream($document->path);
        if (! is_resource($source)) {
            throw new BackupException('A document could not be read from storage.');
        }
        try {
            $output = fopen($destination, 'xb');
            if ($output === false) {
                throw new BackupException('A document could not be staged.');
            }
            try {
                if (stream_copy_to_stream($source, $output) === false) {
                    throw new BackupException('A document could not be staged.');
                }
            } finally {
                fclose($output);
            }
        } finally {
            fclose($source);
        }
        if (hash_file('sha256', $destination) !== $document->sha256) {
            throw new BackupException('A document checksum does not match the database.');
        }
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        if (File::put($path, $json) !== strlen($json)) {
            throw new BackupException('Backup metadata could not be written.');
        }
    }

    private function run(array $command, string $message, array $environment = []): void
    {
        if (Process::timeout(7200)->env($environment)->quietly()->run($command)->failed()) {
            throw new BackupException($message);
        }
    }

    private function prune(string $path, int $keep, string $latest): void
    {
        $files = collect(File::files($path))
            ->reject(fn ($file) => $file->isLink() || $file->getFilename() === $latest)
            ->filter(fn ($file) => preg_match(self::ARCHIVE, $file->getFilename()))
            ->sortByDesc(fn ($file) => $file->getFilename())
            ->slice($keep - 1);
        foreach ($files as $file) {
            if (! File::delete($file->getPathname())) {
                Log::warning('backup.retention_failed', ['archive' => $file->getFilename()]);
            }
        }
    }
}
