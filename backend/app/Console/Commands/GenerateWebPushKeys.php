<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateWebPushKeys extends Command
{
    protected $signature = 'notifications:generate-vapid';

    protected $description = 'Save a new Web Push key pair privately in the server environment';

    public function handle(): int
    {
        $path = realpath(app()->environmentFilePath());
        if ($path === false || ! is_file($path)) {
            $this->error('A server environment file is required.');

            return self::FAILURE;
        }
        $contents = file_get_contents($path);
        if (preg_match('/^WEB_PUSH_(PUBLIC|PRIVATE)_KEY=.+$/m', $contents)) {
            $this->error('Existing Web Push keys were preserved.');

            return self::FAILURE;
        }
        $keys = VAPID::createVapidKeys();
        foreach (['WEB_PUSH_PUBLIC_KEY' => $keys['publicKey'], 'WEB_PUSH_PRIVATE_KEY' => $keys['privateKey']] as $name => $value) {
            $line = $name.'='.$value;
            $contents = preg_match('/^'.preg_quote($name, '/').'=/m', $contents) ? preg_replace('/^'.preg_quote($name, '/').'=.*$/m', $line, $contents) : rtrim($contents)."\n".$line."\n";
        }
        $temporary = $path.'.web-push-'.bin2hex(random_bytes(8)).'.tmp';
        $previousMask = umask(0077);
        try {
            if (file_put_contents($temporary, $contents) === false || ! chmod($temporary, 0600) || ! rename($temporary, $path)) {
                $this->error('The server environment could not be saved.');

                return self::FAILURE;
            }
        } finally {
            umask($previousMask);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $this->info('Web Push keys saved privately. Configure WEB_PUSH_SUBJECT with your HTTPS contact URL or mailto address, then restart application services.');

        return self::SUCCESS;
    }
}
