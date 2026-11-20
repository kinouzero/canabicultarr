<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;

final class DesktopConfiguration
{
    private const LAN_FIELDS = ['LAN_ENABLED', 'LAN_BIND', 'LAN_PORT', 'LAN_HOST', 'LAN_CERT', 'LAN_KEY'];

    public const PORT = 'CANABICULTARR_DESKTOP_PORT';

    public static function parse(string $contents): array
    {
        try {
            return Dotenv::parse($contents);
        } catch (InvalidFileException) {
            throw new RuntimeException('The desktop configuration contains invalid syntax.');
        }
    }

    public static function port(string $value, int $minimum = 1024): int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => 65535]]);
        if ($port === false) {
            throw new RuntimeException("Enter a port between $minimum and 65535.");
        }

        return $port;
    }

    public static function configure(string $path, string $contents, array $settings, array $input): void
    {
        $value = static function (string $field, string $default = '') use ($input): string {
            $text = $input[$field] ?? $default;
            if (! is_string($text) || strlen($text) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $text)) {
                throw new RuntimeException('Invalid field: '.$field.'.');
            }

            return $text;
        };
        $port = self::port($value('port', '8420'));
        $smtpPort = self::port($value('smtpPort', '587'), 1);
        $host = trim($value('host'));
        if (! filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new RuntimeException('Enter a valid SMTP hostname or IP address.');
        }
        $sender = trim($value('sender'));
        if (! filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid sender email address.');
        }
        $username = trim($value('username'));
        $password = $value('password');
        if ($password === '' && $username === ($settings['MAIL_USERNAME'] ?? '')) {
            $password = $settings['MAIL_PASSWORD'] ?? '';
        }

        $network = $input['lanEnabled'] ?? null;
        $lan = $network === null ? [] : self::network([
            'LAN_ENABLED' => $network,
            'LAN_BIND' => $value('lanBind'),
            'LAN_PORT' => $value('lanPort', '8443'),
            'LAN_HOST' => $value('lanHost'),
            'LAN_CERT' => $value('lanCert'),
            'LAN_KEY' => $value('lanKey'),
        ]);
        self::save($path, $contents, [
            ...$lan,
            'APP_KEY' => $settings['APP_KEY'] ?? 'base64:'.base64_encode(random_bytes(32)),
            self::PORT => (string) $port,
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => $host,
            'MAIL_PORT' => (string) $smtpPort,
            'MAIL_FROM_ADDRESS' => $sender,
            'MAIL_FROM_NAME' => trim($value('senderName', 'Canabicultarr')),
            'MAIL_USERNAME' => $username,
            'MAIL_PASSWORD' => $username === '' ? '' : $password,
        ]);
    }

    public static function summary(array $settings): array
    {
        return [
            'configured' => isset($settings['APP_KEY'], $settings['MAIL_HOST'], $settings['MAIL_FROM_ADDRESS']),
            'port' => $settings[self::PORT] ?? '8420',
            'host' => $settings['MAIL_HOST'] ?? '',
            'smtpPort' => $settings['MAIL_PORT'] ?? '587',
            'sender' => $settings['MAIL_FROM_ADDRESS'] ?? '',
            'senderName' => $settings['MAIL_FROM_NAME'] ?? 'Canabicultarr',
            'username' => $settings['MAIL_USERNAME'] ?? '',
            'lanEnabled' => ($settings['LAN_ENABLED'] ?? '0') === '1',
            'lanBind' => $settings['LAN_BIND'] ?? '',
            'lanPort' => $settings['LAN_PORT'] ?? '8443',
            'lanHost' => $settings['LAN_HOST'] ?? '',
            'lanCert' => $settings['LAN_CERT'] ?? '',
            'lanKey' => $settings['LAN_KEY'] ?? '',
        ];
    }

    public static function network(array $settings, bool $resolveBind = false): array
    {
        $enabled = $settings['LAN_ENABLED'] ?? '0';
        if (! in_array($enabled, ['0', '1'], true)) {
            throw new RuntimeException('Invalid Desktop LAN setting.');
        }
        if ($enabled === '0') {
            return ['LAN_ENABLED' => '0'];
        }

        $bind = self::lanHostname($settings['LAN_BIND'] ?? '');
        $host = self::lanHostname($settings['LAN_HOST'] ?? '');
        $address = self::lanBindAddress($bind);
        self::port($settings['LAN_PORT'] ?? '');
        foreach (['LAN_CERT', 'LAN_KEY'] as $field) {
            $path = $settings[$field] ?? '';
            if (! preg_match('~^(?:/|[A-Za-z]:[/\\\\])~', $path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
                throw new RuntimeException('Choose readable PEM certificate and key files.');
            }
        }

        $certificate = file_get_contents($settings['LAN_CERT']);
        $key = file_get_contents($settings['LAN_KEY']);
        if ($certificate === false || $key === false || ! @openssl_x509_check_private_key($certificate, $key)) {
            throw new RuntimeException('The PEM certificate and key do not match.');
        }

        return [...array_intersect_key($settings, array_flip(self::LAN_FIELDS)), 'LAN_BIND' => $resolveBind ? $address : $bind, 'LAN_HOST' => $host];
    }

    private static function lanBindAddress(string $bind): string
    {
        $private = static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && preg_match('/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/', $address);
        if ($private($bind)) {
            return $bind;
        }

        if (! filter_var($bind, FILTER_VALIDATE_IP)) {
            $unicast = array_merge(...array_column(net_get_interfaces() ?: [], 'unicast'));
            $local = array_column($unicast, 'address');
            foreach (gethostbynamel($bind) ?: [] as $address) {
                if ($private($address) && in_array($address, $local, true)) {
                    return $address;
                }
            }
        }

        throw new RuntimeException('Enter a private IPv4 address or a hostname that resolves to this computer on the local network.');
    }

    public static function lanHostname(mixed $host): string
    {
        if (! is_string($host)) {
            throw new RuntimeException('Enter a certificate hostname or private IP address.');
        }
        $host = strtolower(trim($host));
        if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new RuntimeException('Enter a certificate hostname or private IP address.');
        }

        return $host;
    }

    public static function defaultLanHostname(?string $preferred = null): string
    {
        $host = self::lanHostname($preferred ?? gethostname());
        self::lanBindAddress($host);

        return $host;
    }

    public static function saveLanCertificate(string $path, string $contents, array $fields): void
    {
        self::save($path, $contents, array_intersect_key($fields, array_flip([...self::LAN_FIELDS, 'LAN_PREVIOUS_AUTHORITY', 'LAN_PREVIOUS_FINGERPRINT'])));
    }

    public static function disableNetworkOnRestore(string $path): void
    {
        $contents = file_get_contents($path);
        if ((self::parse($contents)['LAN_ENABLED'] ?? '0') === '1') {
            self::save($path, $contents, ['LAN_ENABLED' => '0']);
        }
    }

    private static function save(string $path, string $contents, array $settings): void
    {
        $updated = [];
        foreach ($settings as $name => $value) {
            $contents = preg_replace('/^[\h]*(?:export\h+)?'.preg_quote($name, '/').'\h*=.*(?:\R|$)/m', '', $contents);
            $escaped = strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']);
            $updated[] = "$name=\"$escaped\"";
        }
        $contents = rtrim($contents, "\r\n");
        $contents = ($contents === '' ? '' : $contents."\n").implode("\n", $updated)."\n";
        $parsed = self::parse($contents);
        if (array_intersect_key($parsed, $settings) !== $settings) {
            throw new RuntimeException('The desktop configuration could not be serialized safely.');
        }

        $temporary = tempnam(dirname($path), '.desktop-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary desktop configuration.');
        }
        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $contents) !== strlen($contents) || ! rename($temporary, $path)) {
                throw new RuntimeException('Could not save the desktop configuration.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
