<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

$action = $argv[1] ?? 'serve';
if ($argc > 2 || ! in_array($action, ['configure', 'settings', 'serve', 'setup-token', 'backup', 'restore', 'configure-backups', 'backup-worker', 'generate-lan-certificate', 'remove-lan-certificate', 'export-lan-certificate', 'export-lan-profile', 'runtime-log'], true)) {
    fail('Usage: php infrastructure/desktop/run.php [configure|settings|serve|setup-token|backup|restore|configure-backups|backup-worker|generate-lan-certificate|remove-lan-certificate|export-lan-certificate|export-lan-profile|runtime-log]');
}
$windows = PHP_OS_FAMILY === 'Windows';
if (PHP_VERSION_ID < 80500 || ! in_array(PHP_OS_FAMILY, ['Darwin', 'Linux', 'Windows'], true)) {
    fail('The desktop runtime requires PHP 8.5 on macOS, Linux or Windows.');
}
$requiredExtensions = ['pdo_sqlite', 'intl', 'mbstring', 'bcmath', 'gd', 'zip', 'dom', 'fileinfo'];
if (! $windows) {
    array_push($requiredExtensions, 'pcntl', 'posix', 'exif');
}
foreach ($requiredExtensions as $extension) {
    if (! extension_loaded($extension)) {
        fail("Missing PHP extension: $extension.");
    }
}

$root = dirname(__DIR__, 2);
$backend = $root.'/backend';
$frontend = $root.'/frontend/dist';
if (! is_file($backend.'/vendor/autoload.php') || ($action === 'serve' && ! is_file($frontend.'/index.html'))) {
    fail('Install the backend Composer dependencies and build the frontend first.');
}

$home = getenv('HOME');
$dataDir = getenv('CANABICULTARR_DESKTOP_DATA_DIR');
if ($dataDir === false || $dataDir === '') {
    if ($windows && ($local = getenv('LOCALAPPDATA'))) {
        $dataDir = $local.'/Canabicultarr/Data';
    } elseif ($home) {
        $dataDir = PHP_OS_FAMILY === 'Darwin'
            ? $home.'/Library/Application Support/Canabicultarr'
            : (getenv('XDG_DATA_HOME') ?: $home.'/.local/share').'/canabicultarr';
    } else {
        fail('Set CANABICULTARR_DESKTOP_DATA_DIR to a private data directory.');
    }
}
umask(0077);
if (! is_dir($dataDir) && ! mkdir($dataDir, 0700, true) && ! is_dir($dataDir)) {
    fail('Could not create the desktop data directory.');
}
$dataDir = realpath($dataDir);
if ($dataDir === false || ! is_writable($dataDir) || (! $windows && (fileowner($dataDir) !== posix_geteuid() || (fileperms($dataDir) & 0077) !== 0))) {
    fail('The desktop data directory must be private (0700) and writable by its owner.');
}
require $backend.'/vendor/autoload.php';
require __DIR__.'/Configuration.php';
require __DIR__.'/ProfileBackup.php';
require __DIR__.'/BackupSchedule.php';
require __DIR__.'/LanCertificate.php';
require __DIR__.'/RuntimeLog.php';

$log = new RuntimeLog($dataDir);
if ($action === 'runtime-log') {
    echo $log->contents();
    exit(0);
}

if (in_array($action, ['configure', 'serve', 'backup', 'restore', 'configure-backups', 'generate-lan-certificate', 'remove-lan-certificate'], true)) {
    $lock = fopen($dataDir.'/run.lock', 'c');
    if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
        fail('A desktop instance already uses this data directory.');
    }
}
if ($action === 'serve') {
    $log->record('startup-requested');
}
$config = $dataDir.'/desktop.env';
if (in_array($action, ['serve', 'setup-token'], true) && ! is_file($config)) {
    if ($action === 'serve') {
        $log->record('configuration-missing');
    }
    fail('Complete the Desktop configuration before starting the server.');
}
if (is_link($config) || (! $windows && is_file($config) && (fileowner($config) !== posix_geteuid() || (fileperms($config) & 0077) !== 0))) {
    fail('The desktop configuration must be a private file (0600) owned by the current user.');
}
$contents = is_file($config) ? file_get_contents($config) : '';
if ($contents === false) {
    fail('Could not read the desktop configuration.');
}
try {
    if (in_array($action, ['backup', 'restore'], true)) {
        try {
            ProfileBackup::run($action, $dataDir, json_decode(stream_get_contents(STDIN, 16384), true));
        } catch (PDOException) {
            fail('The Desktop database could not be read.');
        }
        exit(0);
    }
    $settings = DesktopConfiguration::parse($contents);
    $schedule = new BackupSchedule($dataDir, $settings['APP_KEY'] ?? '');
    $certificates = new LanCertificate($dataDir);
    if (in_array($action, ['generate-lan-certificate', 'remove-lan-certificate'], true)) {
        if (! DesktopConfiguration::summary($settings)['configured']) {
            fail('Configure the Desktop profile before managing its local certificate.');
        }
        $input = json_decode(stream_get_contents(STDIN, 2048), true);
        $previous = LanCertificate::identity($certificates->publicCertificate($settings));
        $previousFields = $previous['fingerprint'] === '' ? [] : [
            'LAN_PREVIOUS_AUTHORITY' => $previous['name'],
            'LAN_PREVIOUS_FINGERPRINT' => $previous['fingerprint'],
        ];
        if ($action === 'remove-lan-certificate') {
            if (($input['confirm'] ?? false) !== true) {
                fail('Confirm removal of the local certificate first.');
            }
            DesktopConfiguration::saveLanCertificate($config, $contents, [
                'LAN_ENABLED' => '0',
                'LAN_CERT' => '',
                'LAN_KEY' => '',
                ...$previousFields,
            ]);
        } else {
            if (($settings['LAN_CERT'] ?? '') !== '' && ($input['replace'] ?? false) !== true) {
                fail('Confirm replacement of the current local certificate first.');
            }
            $reset = ($input['defaults'] ?? false) === true;
            $hostname = $reset
                ? DesktopConfiguration::defaultLanHostname(is_string($input['defaultHostname'] ?? null) ? $input['defaultHostname'] : null)
                : DesktopConfiguration::lanHostname($input['hostname'] ?? null);
            $certificates->create(LanCertificate::gateway($root, $windows), $hostname,
                static fn (array $fields) => DesktopConfiguration::saveLanCertificate($config, $contents, [
                    ...$fields,
                    ...($reset ? ['LAN_BIND' => $hostname, 'LAN_PORT' => '8443'] : []),
                    ...$previousFields,
                ]));
        }
        $certificates->discardPrevious($settings);
        exit(0);
    }
    if (in_array($action, ['export-lan-certificate', 'export-lan-profile'], true)) {
        $certificate = $certificates->publicCertificate($settings);
        if ($certificate === null) {
            fail('No generated local certificate is configured.');
        }
        echo $action === 'export-lan-profile' ? LanCertificate::appleProfile($certificate) : $certificate;
        exit(0);
    }

    if ($action === 'configure-backups') {
        if (! DesktopConfiguration::summary($settings)['configured']) {
            fail('Configure the Desktop profile before scheduling backups.');
        }
        $schedule->configure(json_decode(stream_get_contents(STDIN, 16384), true));
        exit(0);
    }
    if ($action === 'backup-worker') {
        while (true) {
            try {
                $schedule->tick(time());
            } catch (Throwable) {
                // An archive failure must not stop the Desktop application.
                fwrite(STDERR, 'The automatic Desktop backup could not be completed.
');
            }
            sleep(30);
        }
    }
    $savedPort = $settings[DesktopConfiguration::PORT] ?? '8420';
    if ($action === 'settings') {
        $authority = LanCertificate::identity($certificates->publicCertificate($settings));
        echo json_encode([
            ...DesktopConfiguration::summary($settings),
            'lanFingerprint' => $authority['fingerprint'],
            'lanAuthority' => $authority['name'],
            'lanPreviousAuthority' => $settings['LAN_PREVIOUS_AUTHORITY'] ?? '',
            'lanPreviousFingerprint' => $settings['LAN_PREVIOUS_FINGERPRINT'] ?? '',
            'backupSchedule' => $schedule->summary(),
        ], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($action === 'configure') {
        $input = json_decode(stream_get_contents(STDIN, 16384), true);
        if (! is_array($input)) {
            fail('The Desktop installer must supply valid settings.');
        }
        DesktopConfiguration::configure($config, $contents, $settings, $input);
        exit(0);
    }
    $port = DesktopConfiguration::port(getenv(DesktopConfiguration::PORT) !== false ? getenv(DesktopConfiguration::PORT) : $savedPort);
} catch (RuntimeException $exception) {
    if ($action === 'serve') {
        $log->record('configuration-invalid');
    }
    fail($exception->getMessage());
}
if (empty($settings['APP_KEY'])) {
    if ($action === 'serve') {
        $log->record('configuration-invalid');
    }
    fail('The desktop application key is missing.');
}
try {
    $lan = $action === 'serve' ? DesktopConfiguration::network($settings, resolveBind: true) : ['LAN_ENABLED' => '0'];
} catch (RuntimeException $exception) {
    $log->record('lan-configuration-invalid');
    fail($exception->getMessage());
}
$environment = [
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    'HOME' => $home ?: $dataDir,
    'CANABICULTARR_DESKTOP_DATA_DIR' => $dataDir,
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'APP_URL' => 'http://127.0.0.1:'.$port,
    'APP_CONFIG_CACHE' => $dataDir.'/cache/config.php',
    'APP_PACKAGES_CACHE' => $dataDir.'/cache/packages.php',
    'APP_SERVICES_CACHE' => $dataDir.'/cache/services.php',
    'APP_ROUTES_CACHE' => $dataDir.'/cache/routes.php',
    'APP_EVENTS_CACHE' => $dataDir.'/cache/events.php',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $dataDir.'/canabicultarr.sqlite',
    'DB_URL' => '',
    'DB_SQLITE_JOURNAL_MODE' => 'wal',
    'CACHE_STORE' => 'file',
    'QUEUE_CONNECTION' => 'database',
    'SESSION_DRIVER' => 'file',
    'SESSION_ENCRYPT' => 'true',
    'SESSION_COOKIE' => 'canabicultarr_desktop_session',
    'DOCUMENTS_DISK' => 'documents',
    'LOG_CHANNEL' => 'single',
];
if ($lan['LAN_ENABLED'] === '1') {
    $environment['CANABICULTARR_DESKTOP_LAN_LISTEN'] = $lan['LAN_BIND'].':'.$lan['LAN_PORT'];
    $environment['CANABICULTARR_DESKTOP_LAN_ORIGIN'] = 'https://'.$lan['LAN_HOST'].':'.$lan['LAN_PORT'];
    $environment['CANABICULTARR_DESKTOP_LAN_CERT'] = $lan['LAN_CERT'];
    $environment['CANABICULTARR_DESKTOP_LAN_KEY'] = $lan['LAN_KEY'];
    $environment['CANABICULTARR_DESKTOP_LOCAL_PORT'] = (string) $port;
    $environment['CANABICULTARR_DESKTOP_PROXY_TOKEN'] = bin2hex(random_bytes(32));
    $environment['APP_URL'] = $environment['CANABICULTARR_DESKTOP_LAN_ORIGIN'];
}
if ($windows) {
    foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'USERPROFILE', 'LOCALAPPDATA'] as $name) {
        if (($value = getenv($name)) !== false) {
            $environment[$name] = $value;
        }
    }
}
$runId = getenv('CANABICULTARR_DESKTOP_RUN_ID');
if (is_string($runId) && preg_match('/^[a-f0-9-]{36}$/D', $runId)) {
    $environment['CANABICULTARR_DESKTOP_RUN_ID'] = $runId;
}
$runtimeIni = dirname(PHP_BINARY).'/php.ini';
if (is_file($runtimeIni) && is_file(dirname(PHP_BINARY).'/cert.pem')) {
    $environment['PHPRC'] = $runtimeIni;
    $environment['PHP_INI_SCAN_DIR'] = dirname(PHP_BINARY).'/ini';
    $environment['CANABICULTARR_CA_FILE'] = dirname(PHP_BINARY).'/cert.pem';
    if (PHP_OS_FAMILY === 'Linux' && is_dir(dirname(PHP_BINARY).'/lib')) {
        $environment['LD_LIBRARY_PATH'] = dirname(PHP_BINARY).'/lib';
        $environment['CANABICULTARR_PHP_EXTENSIONS'] = dirname(PHP_BINARY).'/extensions';
    }
}
$artisan = [$backend.'/artisan'];
if ($action === 'setup-token') {
    if (! is_file($dataDir.'/desktop.env') || ! is_file($environment['DB_DATABASE'])) {
        fail('Start the desktop instance once before requesting a setup token.');
    }
    $command = proc_open([PHP_BINARY, ...$artisan, 'app:setup-token', '--raw', '--no-interaction'], [STDIN, STDOUT, STDERR], $pipes, $backend, $environment, ['bypass_shell' => true]);
    exit(is_resource($command) ? proc_close($command) : 1);
}

foreach (['cache', 'storage/app/documents', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
    $path = $dataDir.'/'.$directory;
    if (! is_dir($path) && ! mkdir($path, 0700, true)) {
        $log->record('storage-unavailable');
        fail("Could not create desktop directory: $directory.");
    }
}
if (! is_file($environment['DB_DATABASE']) && ! touch($environment['DB_DATABASE'])) {
    $log->record('storage-unavailable');
    fail('Could not create the desktop SQLite database.');
}
$initialize = proc_open([PHP_BINARY, ...$artisan, 'app:initialize', '--no-interaction'], [STDIN, STDOUT, STDERR], $pipes, $backend, $environment, ['bypass_shell' => true]);
if (! is_resource($initialize) || proc_close($initialize) !== 0) {
    $log->record('database-initialization-failed');
    fail('Desktop database initialization failed.');
}

$commands = [
    'web' => ['-d', 'expose_php=0', '-d', 'display_errors=0', '-d', 'upload_max_filesize=10M', '-d', 'post_max_size=12M', '-S', '127.0.0.1:'.$port, '-t', $frontend, __DIR__.'/router.php'],
    'queue' => [...$artisan, 'queue:work', '--queue=password-recovery,account-activation,controls,default,integrations,exports', '--sleep=1', '--tries=3', '--timeout=60', '--no-interaction'],
    'scheduler' => [...$artisan, 'schedule:work', '--no-interaction'],
    'pump-timers' => [...$artisan, 'pumps:work', '--no-interaction'],
];
if ($lan['LAN_ENABLED'] === '1') {
    try {
        $gateway = LanCertificate::gateway($root, $windows);
    } catch (RuntimeException $error) {
        $log->record('service-unavailable', 'lan');
        fail($error->getMessage());
    }
    $commands['lan'] = [$gateway];
}
if ($schedule->summary()['frequency'] !== 'off') {
    $commands['backup-worker'] = [__FILE__, 'backup-worker'];
}

$running = true;
$stopFile = $windows && isset($environment['CANABICULTARR_DESKTOP_RUN_ID']) ? $dataDir.'/stop-'.$runId : null;
if ($windows && $stopFile === null) {
    fail('Start the Windows server from the Canabicultarr launcher.');
}
if (! $windows) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function () use (&$running) {
        $running = false;
    });
    pcntl_signal(SIGTERM, static function () use (&$running) {
        $running = false;
    });
}
$processes = [];
$nextBackupWorkerStart = isset($commands['backup-worker']) ? 0 : PHP_INT_MAX;
$exit = 0;
$start = static function (string $name) use ($commands, $backend, $environment, $windows, $log) {
    // Separate process groups also cover background children started by the scheduler.
    $service = $name === 'lan' ? $commands[$name] : [PHP_BINARY, ...$commands[$name]];
    $command = $windows
        ? $service
        : [PHP_BINARY, '-r', 'if (posix_setsid() < 0) exit(1); pcntl_exec($argv[1], array_slice($argv, 2));', '--', ...$service];
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $backend, $environment, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        $log->record('service-start-failed', $name);
        throw new RuntimeException("Could not start the desktop $name service.");
    }

    return $process;
};
try {
    foreach (array_diff(array_keys($commands), ['backup-worker']) as $name) {
        $processes[$name] = $start($name);
    }
    $log->record('services-launched');
    echo "Canabicultarr Desktop: {$environment['APP_URL']}\n";
    while ($running) {
        if ($stopFile !== null) {
            clearstatcache(true, $stopFile);
            if (is_file($stopFile)) {
                break;
            }
        }
        if (time() >= $nextBackupWorkerStart) {
            try {
                $processes['backup-worker'] = $start('backup-worker');
                $nextBackupWorkerStart = PHP_INT_MAX;
            } catch (RuntimeException) {
                fwrite(STDERR, "The automatic Desktop backup worker could not start.\n");
                $nextBackupWorkerStart = time() + 3600;
            }
        }
        foreach ($processes as $name => $process) {
            $status = proc_get_status($process);
            if ($status['running'] || ! $running) {
                continue;
            }
            if ($name === 'backup-worker') {
                proc_close($process);
                unset($processes[$name]);
                $nextBackupWorkerStart = time() + 3600;
                $log->record('service-exited', $name);
                fwrite(STDERR, "The automatic Desktop backup worker stopped.\n");

                continue;
            }
            // Pump timers intentionally recycle their Laravel process every hour.
            if ($name === 'pump-timers' && $status['exitcode'] === 0) {
                proc_close($process);
                unset($processes[$name]);
                $processes[$name] = $start($name);

                continue;
            }
            $log->record('service-exited', $name);
            throw new RuntimeException("The desktop $name service stopped.");
        }
        usleep(200000);
    }
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    $exit = 1;
} finally {
    foreach ($processes as $process) {
        $pid = proc_get_status($process)['pid'];
        if ($windows || ! posix_kill(-$pid, SIGTERM)) {
            proc_terminate($process);
        }
    }
    $deadline = microtime(true) + 5;
    do {
        $active = array_filter($processes, static fn ($process) => proc_get_status($process)['running']);
        if ($active) {
            usleep(100000);
        }
    } while ($active && microtime(true) < $deadline);
    foreach ($processes as $process) {
        $pid = proc_get_status($process)['pid'];
        if (! $windows) {
            posix_kill(-$pid, SIGKILL);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
    }
}
$log->record($exit === 0 ? 'server-stopped' : 'server-failed');
exit($exit);
