<?php

namespace App\Services;

use GuzzleHttp\ClientInterface;
use Illuminate\Http\Request;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\OpenIDConnect\Provider;

class OidcClient
{
    public function __construct(private ?ClientInterface $http = null) {}

    public function enabled(): bool
    {
        $config = config('oidc');
        if (! $config['enabled']) {
            return false;
        }
        foreach (['issuer', 'client_id', 'client_secret', 'redirect'] as $key) {
            if (! is_string($config[$key]) || trim($config[$key]) === '') {
                return false;
            }
        }

        // Credentials are never sent to a plaintext remote issuer.
        return $this->validUrl($config['issuer']) && $this->validUrl($config['redirect']);
    }

    private function validUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && (($parts['scheme'] ?? '') === 'https'
                || (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)));
    }

    public function driver(Request $request): Provider
    {
        $config = config('oidc');
        $driver = new Provider($request, $config['client_id'], $config['client_secret'], $config['redirect']);
        $driver->setConfig(new Config($config['client_id'], $config['client_secret'], $config['redirect'], [
            'base_url' => $config['issuer'],
            'issuer' => $config['issuer'],
            'verify_jwt' => true,
            'use_nonce' => true,
            'require_email' => true,
            'jwt_algorithm' => ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512', 'EdDSA'],
            'http_timeout' => 10,
            'http_connect_timeout' => 5,
            'guzzle' => ['allow_redirects' => false],
        ]));
        if ($this->http) {
            $driver->setHttpClient($this->http);
        }

        return $driver;
    }
}
