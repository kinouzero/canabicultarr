<?php

declare(strict_types=1);

final class ProfileBackup
{
    private const DATABASE = 'canabicultarr.sqlite';

    private const SETTINGS = 'desktop.env';

    private const DOCUMENTS = 'storage/app/documents/';

    private const MAX_BYTES = 8589934592;

    private const MAX_ENTRIES = 100002;

    public static function run(string $action, string $profile, mixed $input, bool $online = false): void
    {
        $path = $input['path'] ?? null;
        $password = $input['password'] ?? null;
        if (! is_string($path) || ! preg_match('~^(?:/|[A-Za-z]:[/\\\\])~', $path) || str_contains($path, "\0")
            || ! is_string($password) || strlen($password) < 12 || strlen($password) > 256 || str_contains($password, "\0")) {
            throw new RuntimeException('Choose a backup file and a password of at least 12 characters.');
        }
        if (! ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, $action === 'backup')) {
            throw new RuntimeException('This PHP runtime does not support encrypted ZIP archives.');
        }
        $work = $online ? $profile.'/.scheduled-backup-work' : $profile.'/.backup-'.bin2hex(random_bytes(12));
        if ($online && (file_exists($work) || is_link($work))) {
            self::removeTree($work);
        }
        if (! mkdir($work, 0700)) {
            throw new RuntimeException('Could not prepare the backup operation.');
        }
        try {
            if ($action === 'backup') {
                self::export($profile, $work, $path, $password, $online);
            } else {
                self::restore($profile, $work, $path, $password);
            }
        } finally {
            self::removeTree($work);
        }
    }

    private static function export(string $profile, string $work, string $path, string $password, bool $online): void
    {
        if (file_exists($path) || is_link($path) || is_link($profile.'/'.self::DATABASE) || ! is_file($profile.'/'.self::DATABASE)
            || ! is_file($profile.'/'.self::SETTINGS)) {
            throw new RuntimeException('Choose a new archive file and configure the Desktop profile first.');
        }
        $database = new PDO('sqlite:'.$profile.'/'.self::DATABASE);
        $database->exec('PRAGMA busy_timeout = 5000');
        $snapshot = $work.'/'.self::DATABASE;
        $database->exec('VACUUM INTO '.$database->quote($snapshot));
        unset($database);

        $temporary = dirname($path).'/.canabicultarr-'.bin2hex(random_bytes(12)).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not open the backup destination.');
        }
        try {
            $zip->setPassword($password);
            $bytes = 0;
            self::add($zip, $snapshot, self::DATABASE, $bytes);
            self::add($zip, $profile.'/'.self::SETTINGS, self::SETTINGS, $bytes);
            foreach (self::documents($snapshot) as $record) {
                $name = self::documentName($record);
                $source = $profile.'/'.$name;
                if (! is_file($source) || is_link($source)) {
                    throw new RuntimeException('A private document is missing.');
                }
                if ($online) {
                    $copy = $work.'/'.$name;
                    if ((! is_dir(dirname($copy)) && ! mkdir(dirname($copy), 0700, true)) || ! copy($source, $copy)) {
                        throw new RuntimeException('A private document changed during backup.');
                    }
                    $source = $copy;
                }
                if (hash_file('sha256', $source) !== $record['sha256']) {
                    throw new RuntimeException('A private document is missing or does not match its checksum.');
                }
                self::add($zip, $source, $name, $bytes);
            }
            if (! $zip->close() || ! link($temporary, $path)) {
                throw new RuntimeException('Could not publish the backup. Choose a new file on a local disk.');
            }
        } finally {
            unset($zip);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function add(ZipArchive $zip, string $source, string $name, int &$bytes): void
    {
        $size = filesize($source);
        if ($size === false || $zip->numFiles >= self::MAX_ENTRIES) {
            throw new RuntimeException('The backup file count is too large or a file could not be read.');
        }
        $bytes += $size;
        if ($bytes > self::MAX_BYTES || ! $zip->addFile($source, $name)
            || ! $zip->setEncryptionName($name, ZipArchive::EM_AES_256)) {
            throw new RuntimeException('Could not encrypt the backup or the profile exceeds 8 GiB.');
        }
    }

    private static function restore(string $profile, string $work, string $path, string $password): void
    {
        $documents = $profile.'/'.rtrim(self::DOCUMENTS, '/');
        foreach ([$profile.'/'.self::SETTINGS, $profile.'/'.self::DATABASE, $documents] as $entry) {
            if (file_exists($entry) || is_link($entry)) {
                throw new RuntimeException('Restore requires a fresh Desktop profile.');
            }
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Could not open the backup archive.');
        }
        try {
            $zip->setPassword($password);
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('The backup contains too many files.');
            }
            $bytes = 0;
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = $entry['name'] ?? '';
                if (isset($seen[$name]) || ! self::validName($name)
                    || ($entry['encryption_method'] ?? null) !== ZipArchive::EM_AES_256) {
                    throw new RuntimeException('The backup contains an unsupported entry.');
                }
                $seen[$name] = true;
                $bytes += $entry['size'];
                if ($bytes > self::MAX_BYTES || ($name === self::SETTINGS && $entry['size'] > 16384)) {
                    throw new RuntimeException('The backup exceeds the restore size limit.');
                }
                self::extract($zip, $index, $work.'/'.$name, $entry['size']);
            }
            self::validate($work, $seen);
            DesktopConfiguration::disableNetworkOnRestore($work.'/'.self::SETTINGS);
        } finally {
            $zip->close();
        }

        $moved = [];
        try {
            foreach ([rtrim(self::DOCUMENTS, '/'), self::DATABASE, self::SETTINGS] as $name) {
                $source = $work.'/'.$name;
                if (! file_exists($source)) {
                    continue;
                }
                $target = $profile.'/'.$name;
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0700, true);
                }
                if (! rename($source, $target)) {
                    throw new RuntimeException('Could not restore the profile to the local disk.');
                }
                $moved[] = $target;
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($moved) as $target) {
                self::removeTree($target);
            }
            throw $exception;
        }
    }

    private static function extract(ZipArchive $zip, int $index, string $target, int $size): void
    {
        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700, true)) {
            throw new RuntimeException('Could not prepare the restored files.');
        }
        $source = @$zip->getStreamIndex($index);
        if ($source === false) {
            throw new RuntimeException('The backup password is incorrect or the archive is damaged.');
        }
        try {
            $output = fopen($target, 'xb');
            if ($output === false) {
                throw new RuntimeException('Could not write a restored file.');
            }
            try {
                if (@stream_copy_to_stream($source, $output, $size + 1) !== $size || ! feof($source) || ! fflush($output)) {
                    throw new RuntimeException('A backup entry is damaged or could not be written.');
                }
            } finally {
                fclose($output);
            }
        } finally {
            fclose($source);
        }
    }

    private static function validate(string $work, array $seen): void
    {
        if (! isset($seen[self::DATABASE], $seen[self::SETTINGS])) {
            throw new RuntimeException('The backup is missing the Desktop database or settings.');
        }
        $settings = DesktopConfiguration::parse(file_get_contents($work.'/'.self::SETTINGS));
        $key = $settings['APP_KEY'] ?? '';
        if (! DesktopConfiguration::summary($settings)['configured'] || ! preg_match('/^base64:[A-Za-z0-9+\/]{43}=$/D', $key)) {
            throw new RuntimeException('The backup contains invalid Desktop settings.');
        }
        DesktopConfiguration::port($settings[DesktopConfiguration::PORT] ?? '8420');
        $database = $work.'/'.self::DATABASE;
        $check = new PDO('sqlite:'.$database);
        $check->exec('PRAGMA trusted_schema = OFF');
        if ($check->query('PRAGMA quick_check')->fetchColumn() !== 'ok'
            || (int) $check->query("SELECT count(*) FROM sqlite_schema WHERE type = 'table' AND name IN ('migrations', 'users', 'documents')")->fetchColumn() !== 3) {
            throw new RuntimeException('The backup database is damaged.');
        }
        unset($check);
        unset($seen[self::SETTINGS], $seen[self::DATABASE]);
        foreach (self::documents($database) as $record) {
            $name = self::documentName($record);
            if (! isset($seen[$name]) || hash_file('sha256', $work.'/'.$name) !== $record['sha256']) {
                throw new RuntimeException('A backup document is missing or does not match its checksum.');
            }
            unset($seen[$name]);
        }
        if ($seen !== []) {
            throw new RuntimeException('The backup contains an unregistered document.');
        }
    }

    private static function documents(string $database): iterable
    {
        $connection = new PDO('sqlite:'.$database);
        $connection->exec('PRAGMA trusted_schema = OFF');
        $result = $connection->query('SELECT disk, path, sha256 FROM documents');
        while ($record = $result->fetch(PDO::FETCH_ASSOC)) {
            yield $record;
        }
    }

    private static function documentName(array $record): string
    {
        $name = self::DOCUMENTS.$record['path'];
        if ($record['disk'] !== 'documents' || ! self::validName($name)) {
            throw new RuntimeException('Desktop backups require all documents to be stored locally.');
        }

        return $name;
    }

    private static function validName(string $name): bool
    {
        return in_array($name, [self::SETTINGS, self::DATABASE], true)
            || preg_match('~^storage/app/documents/[a-f0-9-]{36}/original\.[a-z0-9]+$~D', $name) === 1;
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (new FilesystemIterator($path) as $entry) {
                self::removeTree($entry->getPathname());
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
