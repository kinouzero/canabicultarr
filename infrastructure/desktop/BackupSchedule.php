<?php

declare(strict_types=1);

use Illuminate\Encryption\Encrypter;

final class BackupSchedule
{
    private const DEFAULT_KEEP = 7;

    private const INTERVALS = ['daily' => 86400, 'weekly' => 604800];

    public function __construct(private readonly string $profile, private readonly string $applicationKey) {}

    public function summary(): array
    {
        $schedule = $this->read('backup-schedule.json');
        $status = $this->read('backup-status.json');

        return [
            'frequency' => $schedule['frequency'] ?? 'off',
            'directory' => $schedule['directory'] ?? $this->profile.'/backups',
            'keep' => $schedule['keep'] ?? self::DEFAULT_KEEP,
            'hasPassword' => isset($schedule['password']),
            'lastSuccess' => $status['lastSuccess'] ?? null,
            'failed' => $status['failed'] ?? false,
        ];
    }

    public function configure(mixed $input): void
    {
        $frequency = $input['frequency'] ?? null;
        $directory = $input['directory'] ?? '';
        $password = $input['password'] ?? '';
        $keep = filter_var($input['keep'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);
        if (! is_string($frequency) || ($frequency !== 'off' && ! isset(self::INTERVALS[$frequency]))
            || ! is_string($directory) || strlen($directory) > 4096 || str_contains($directory, "\0")
            || ! is_string($password) || $keep === false) {
            throw new RuntimeException('Invalid automatic backup settings.');
        }
        $old = $this->read('backup-schedule.json');
        $schedule = ['frequency' => $frequency, 'directory' => $old['directory'] ?? $this->profile.'/backups', 'keep' => $keep];
        if (isset($old['password'])) {
            $schedule['password'] = $old['password'];
        }
        if ($frequency !== 'off') {
            $directory = trim($directory) ?: $this->profile.'/backups';
            if (! preg_match('~^(?:/|[A-Za-z]:[/\\\\])~', $directory)) {
                throw new RuntimeException('Choose an absolute backup directory.');
            }
            if ($directory === $this->profile.'/backups' && ! is_dir($directory)) {
                mkdir($directory, 0700);
            }
            $resolved = realpath($directory);
            if ($resolved === false || ! is_dir($resolved) || ! is_writable($resolved)) {
                throw new RuntimeException('Choose an existing writable backup directory.');
            }
            $schedule['directory'] = $resolved;
            if ($password !== '') {
                if (strlen($password) < 12 || strlen($password) > 256 || str_contains($password, "\0")) {
                    throw new RuntimeException('Enter a backup password of at least 12 characters.');
                }
                $schedule['password'] = $this->encrypter()->encryptString($password);
            }
            if (! isset($schedule['password'])) {
                throw new RuntimeException('Enter an archive password to enable automatic backups.');
            }
        }
        $this->write('backup-schedule.json', $schedule);
    }

    public function tick(int $now): void
    {
        $schedule = $this->read('backup-schedule.json');
        $interval = self::INTERVALS[$schedule['frequency'] ?? ''] ?? null;
        if ($interval === null) {
            return;
        }
        $lock = fopen($this->profile.'/backup-schedule.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Could not open the automatic backup lock.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            $status = $this->read('backup-status.json');
            $lastSuccess = $status['lastSuccess'] ?? null;
            if (($lastSuccess !== null && $now - $lastSuccess < $interval)
                || ($now - ($status['lastAttempt'] ?? 0) < 3600)) {
                return;
            }
            $this->write('backup-status.json', ['lastSuccess' => $lastSuccess, 'lastAttempt' => $now, 'failed' => true]);
            $prefix = 'canabicultarr-auto-'.substr(hash('sha256', $this->applicationKey), 0, 16).'-';
            $name = $prefix.gmdate('Ymd\THis\Z', $now).'-'.bin2hex(random_bytes(4)).'.zip';
            $directory = $schedule['directory'];
            $path = $directory.'/'.$name;
            ProfileBackup::run('backup', $this->profile, [
                'path' => $path,
                'password' => $this->encrypter()->decryptString($schedule['password']),
            ], online: true);
            $this->write('backup-status.json', ['lastSuccess' => $now, 'lastAttempt' => $now, 'failed' => false]);
            $this->prune($directory, $prefix, $name, $schedule['keep']);
        } finally {
            fclose($lock);
        }
    }

    private function prune(string $directory, string $prefix, string $latest, int $keep): void
    {
        $archives = [];
        foreach (new FilesystemIterator($directory) as $entry) {
            $name = $entry->getFilename();
            if ($name !== $latest && ! $entry->isLink() && $entry->isFile()
                && preg_match('/^'.preg_quote($prefix, '/').'\d{8}T\d{6}Z-[a-f0-9]{8}\.zip$/D', $name)) {
                $archives[$name] = $entry->getPathname();
            }
        }
        krsort($archives);
        foreach (array_slice($archives, $keep - 1) as $path) {
            if (! @unlink($path)) {
                fwrite(STDERR, "An old automatic backup could not be removed.\n");
            }
        }
    }

    private function encrypter(): Encrypter
    {
        $key = str_starts_with($this->applicationKey, 'base64:') ? base64_decode(substr($this->applicationKey, 7), true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('The Desktop profile encryption key is unavailable.');
        }

        return new Encrypter($key, 'AES-256-CBC');
    }

    private function read(string $name): array
    {
        $path = $this->profile.'/'.$name;
        if (! is_file($path) || is_link($path)) {
            return [];
        }
        $contents = file_get_contents($path, length: 16384);
        $value = $contents === false ? null : json_decode($contents, true);

        return is_array($value) ? $value : [];
    }

    private function write(string $name, array $value): void
    {
        $temporary = tempnam($this->profile, '.backup-setting-');
        if ($temporary === false) {
            throw new RuntimeException('Could not prepare automatic backup settings.');
        }
        try {
            $contents = json_encode($value, JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $contents) !== strlen($contents) || ! rename($temporary, $this->profile.'/'.$name)) {
                throw new RuntimeException('Could not save automatic backup settings.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
