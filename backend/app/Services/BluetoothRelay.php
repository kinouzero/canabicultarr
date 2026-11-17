<?php

namespace App\Services;

use App\Exceptions\MeasurementException;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

class BluetoothRelay
{
    public function request(string $action, ?string $address): array
    {
        $url = rtrim((string) config('bluetooth.relay_url'), '/');
        $token = (string) config('bluetooth.relay_token');
        $parts = parse_url($url);
        $host = app(WebhookDestination::class)->host($url);
        if (! $host || ! is_array($parts) || isset($parts['query']) || ! empty($parts['path'])
            || (($parts['scheme'] ?? '') !== 'https' && ! config('bluetooth.relay_allow_http'))
            || strlen($token) < 32 || strlen($token) > 256 || preg_match('/[^\x21-\x7e]/', $token)) {
            throw new MeasurementException('bluetooth.errors.relayConfiguration');
        }
        try {
            $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
            $addresses = $literal ? [$host] : app(WebhookDnsResolver::class)->addresses($host);
            if (! $addresses || count($addresses) > 16) {
                throw new MeasurementException('bluetooth.errors.relayConfiguration');
            }
            foreach ($addresses as $ip) {
                if (IpUtils::checkIp($ip, ['100.100.100.200/32', 'fd00:ec2::254/128']) || ! IpUtils::checkIp($ip, ['127.0.0.0/8', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7'])) {
                    throw new MeasurementException('bluetooth.errors.relayConfiguration');
                }
            }
            $ip = $addresses[0];
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            $target = str_contains($ip, ':') ? '['.$ip.']' : $ip;
            $ca = (string) config('bluetooth.relay_ca_file');
            $options = ['stream' => true, 'proxy' => '', 'verify' => $ca !== '' ? $ca : true,
                'curl' => $literal ? [] : [CURLOPT_RESOLVE => [$host.':'.$port.':'.$target]]];
            $response = Http::acceptJson()->withToken($token)->setHandler(new CurlHandler)->connectTimeout(3)->timeout(28)->withoutRedirecting()->withOptions($options)
                ->post($url.'/bluetooth', ['action' => $action, 'adapter' => config('bluetooth.adapter'), 'address' => $address]);
            try {
                if (! $response->successful()) {
                    throw new MeasurementException('bluetooth.errors.unavailable');
                }
                $stream = $response->toPsrResponse()->getBody();
                $text = '';
                while (! $stream->eof() && strlen($text) <= 262144) {
                    $text .= $stream->read(min(65536, 262145 - strlen($text)));
                }
                if (strlen($text) > 262144) {
                    throw new MeasurementException('bluetooth.errors.invalidReading');
                }
                $payload = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($payload)) {
                    throw new MeasurementException('bluetooth.errors.invalidReading');
                }

                return $payload;
            } finally {
                $response->close();
            }
        } catch (MeasurementException $error) {
            throw $error;
        } catch (Throwable) {
            throw new MeasurementException('bluetooth.errors.unavailable');
        }
    }
}
