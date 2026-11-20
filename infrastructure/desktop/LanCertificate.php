<?php

declare(strict_types=1);

final class LanCertificate
{
    private const FILES = ['trust.cer', 'server.pem', 'server.key'];

    public function __construct(private readonly string $profile) {}

    public static function gateway(string $root, bool $windows): string
    {
        $name = 'desktop-lan'.($windows ? '.exe' : '');
        $packaged = dirname(PHP_BINARY).'/'.$name;
        $path = is_file($packaged) ? $packaged : $root.'/.desktop-build/'.$name;
        if (! is_file($path)) {
            throw new RuntimeException('The Desktop HTTPS gateway is missing.');
        }

        return $path;
    }

    public function create(string $gateway, string $host, callable $persist): void
    {
        $base = $this->base();
        if (is_link($base) || (! is_dir($base) && ! mkdir($base, 0700))) {
            throw new RuntimeException('The local certificate directory could not be created.');
        }
        $directory = $base.'/'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Could not prepare local certificate generation.');
        }
        try {
            $process = proc_open([$gateway, 'issue', $host, $directory], [STDIN, STDOUT, STDERR], $pipes, null, null, ['bypass_shell' => true]);
            if (! is_resource($process) || proc_close($process) !== 0) {
                throw new RuntimeException('The local HTTPS certificate could not be generated.');
            }
            $persist([
                'LAN_HOST' => $host,
                'LAN_CERT' => $directory.'/server.pem',
                'LAN_KEY' => $directory.'/server.key',
            ]);
        } catch (Throwable $error) {
            $this->remove($directory);

            throw $error;
        }
    }

    public function publicCertificate(array $settings): ?string
    {
        $directory = $this->ownedDirectory($settings);
        if ($directory === null) {
            return null;
        }
        $path = $directory.'/trust.cer';
        if (is_link($path) || ! is_file($path)) {
            return null;
        }
        $size = filesize($path);
        $certificate = $size > 0 && $size <= 8192 ? file_get_contents($path) : false;

        return $certificate === false ? null : $certificate;
    }

    public static function identity(?string $certificate): array
    {
        if ($certificate === null) {
            return ['name' => '', 'fingerprint' => ''];
        }

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($certificate), 64, "\n")."-----END CERTIFICATE-----\n";
        $name = openssl_x509_parse($pem)['subject']['CN'] ?? '';
        $fingerprint = strtoupper(implode(':', str_split(hash('sha256', $certificate), 2)));

        return [
            'name' => is_string($name) && preg_match('/^Canabicultarr Desktop [a-f0-9]{8}$/D', $name) ? $name : '',
            'fingerprint' => $fingerprint,
        ];
    }

    public static function appleProfile(string $certificate): string
    {
        $fingerprint = hash('sha256', $certificate);
        $displayName = self::identity($certificate)['name'] ?: 'Canabicultarr Desktop';
        $uuid = static fn (string $purpose): string => vsprintf('%s%s-%s-8%.3s-a%.3s-%s%s%s', str_split(substr(hash('sha256', $purpose.$certificate), 0, 32), 4));

        return sprintf(<<<'PLIST'
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
            <plist version="1.0">
            <dict>
                <key>PayloadType</key><string>Configuration</string>
                <key>PayloadVersion</key><integer>1</integer>
                <key>PayloadIdentifier</key><string>com.canabicultarr.desktop.ca.%s</string>
                <key>PayloadUUID</key><string>%s</string>
                <key>PayloadDisplayName</key><string>%s</string>
                <key>PayloadContent</key><array><dict>
                    <key>PayloadType</key><string>com.apple.security.root</string>
                    <key>PayloadVersion</key><integer>1</integer>
                    <key>PayloadIdentifier</key><string>com.canabicultarr.desktop.ca.%s.certificate</string>
                    <key>PayloadUUID</key><string>%s</string>
                    <key>PayloadDisplayName</key><string>Canabicultarr Desktop CA</string>
                    <key>PayloadCertificateFileName</key><string>Canabicultarr-Desktop-CA.cer</string>
                    <key>PayloadContent</key><data>%s</data>
                </dict></array>
            </dict>
            </plist>
            PLIST, $fingerprint, $uuid('profile'), $displayName, $fingerprint, $uuid('certificate'), base64_encode($certificate));
    }

    public function discardPrevious(array $settings): void
    {
        if (($directory = $this->ownedDirectory($settings)) !== null) {
            $this->remove($directory);
        }
    }

    private function base(): string
    {
        return str_replace('\\', '/', $this->profile).'/lan-certificates';
    }

    private function ownedDirectory(array $settings): ?string
    {
        $cert = str_replace('\\', '/', $settings['LAN_CERT'] ?? '');
        if (! preg_match('~^'.preg_quote($this->base(), '~').'/([a-f0-9]{32})/server\.pem$~D', $cert, $match)) {
            return null;
        }
        $directory = $this->base().'/'.$match[1];

        return str_replace('\\', '/', $settings['LAN_KEY'] ?? '') === $directory.'/server.key'
            && ! is_link($this->base()) && ! is_link($directory) && is_dir($directory) ? $directory : null;
    }

    private function remove(string $directory): void
    {
        foreach (self::FILES as $name) {
            if (is_file($directory.'/'.$name) || is_link($directory.'/'.$name)) {
                @unlink($directory.'/'.$name);
            }
        }
        @rmdir($directory);
    }
}
