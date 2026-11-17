<?php

namespace App\Services;

class PushEndpoint
{
    public function allowed(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (strlen($endpoint) > 2048 || preg_match('/[\x00-\x20\x7f]/', $endpoint) || ! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (($url['port'] ?? 443) !== 443)) {
            return false;
        }
        $host = strtolower($url['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) || ! preg_match('/^[a-z0-9.-]+$/D', $host)) {
            return false;
        }
        foreach (config('alert_notifications.push.allowed_hosts', []) as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)) && strlen($host) > strlen($allowed) - 1)) {
                return true;
            }
        }

        return false;
    }

    public function validKeys(array $keys): bool
    {
        $decode = function ($value) {
            if (! is_string($value) || strlen($value) > 100 || ! preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value)) {
                return false;
            }

            return base64_decode(strtr($value, '-_', '+/'), true);
        };
        $public = $decode($keys['p256dh'] ?? null);
        $auth = $decode($keys['auth'] ?? null);

        return is_string($public) && strlen($public) === 65 && $public[0] === "\x04" && is_string($auth) && strlen($auth) === 16;
    }
}
