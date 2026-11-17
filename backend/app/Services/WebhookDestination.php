<?php

namespace App\Services;

use App\Exceptions\WebhookDestinationException;
use Symfony\Component\HttpFoundation\IpUtils;

class WebhookDestination
{
    private const LAN_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7'];

    public function __construct(private WebhookDnsResolver $resolver) {}

    public function host(string $url): ?string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || (($parts['port'] ?? 1) < 1) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}$/D', $host)) {
            return null;
        }
        if (strlen($host) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $host)) {
            return null;
        }

        return $host;
    }

    public function options(string $url): array
    {
        $host = $this->host($url);
        if ($host === null || ! extension_loaded('curl')) {
            throw new WebhookDestinationException;
        }
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $literal ? [$host] : $this->resolver->addresses($host);
        if ($addresses === []) {
            throw new WebhookDestinationException(retryable: true);
        }
        if (count($addresses) > 256) {
            throw new WebhookDestinationException;
        }
        foreach ($addresses as $address) {
            if (! $this->allowed($address, $host)) {
                throw new WebhookDestinationException;
            }
        }
        $address = $addresses[0];
        $port = parse_url($url, PHP_URL_PORT) ?? (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $target = str_contains($address, ':') ? '['.$address.']' : $address;

        // Pin the validated address while retaining the URL hostname for TLS/SNI.
        // Disable proxies: they could resolve the hostname independently.
        return ['proxy' => '', 'force_ip_resolve' => str_contains($address, ':') ? 'v6' : 'v4',
            'curl' => $literal ? [] : [CURLOPT_RESOLVE => [$host.':'.$port.':'.$target]],
        ];
    }

    public function validInternalList(string $value): bool
    {
        if (strlen($value) > 2048 || count(explode(',', $value)) > 50) {
            return false;
        }
        foreach (explode(',', $value) as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            if (! str_contains($entry, '/')) {
                if ($this->host('http://'.(str_contains($entry, ':') ? '['.$entry.']' : $entry)) !== $entry) {
                    return false;
                }

                continue;
            }
            [$network, $prefix] = explode('/', $entry, 2);
            if (! filter_var($network, FILTER_VALIDATE_IP) || ! ctype_digit($prefix)
                || (int) $prefix > (str_contains($network, ':') ? 128 : 32)) {
                return false;
            }
            $lan = false;
            foreach (self::LAN_RANGES as $range) {
                if ((int) $prefix >= (int) explode('/', $range)[1] && IpUtils::checkIp($network, $range)) {
                    $lan = true;
                    break;
                }
            }
            if (! $lan) {
                return false;
            }
        }

        return true;
    }

    private function allowed(string $address, string $host): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP) || IpUtils::checkIp($address, ['100.100.100.200/32', '168.63.129.16/32'])) {
            return false;
        }
        // Symfony's maintained special-use list is supplemented with multicast.
        if (! IpUtils::isPrivateIp($address) && ! IpUtils::checkIp($address, ['224.0.0.0/4', 'ff00::/8', '192.0.0.0/24'])) {
            return true;
        }
        // Exceptions apply only to actual LAN addresses, never metadata,
        // loopback, link-local, documentation or IPv4-embedding IPv6 ranges.
        if (! IpUtils::checkIp($address, self::LAN_RANGES)) {
            return false;
        }
        $configuration = (string) config('alert_notifications.webhook.allowed_internal', '');
        if (! $this->validInternalList($configuration)) {
            return false;
        }
        $allowed = explode(',', $configuration);
        foreach ($allowed as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry !== '' && ($entry === $host || IpUtils::checkIp($address, $entry))) {
                return true;
            }
        }

        return false;
    }
}
