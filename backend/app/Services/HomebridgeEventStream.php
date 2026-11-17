<?php

namespace App\Services;

use App\Exceptions\MeasurementException;
use ElephantIO\Client;
use ElephantIO\Exception\UnsuccessfulOperationException;

class HomebridgeEventStream
{
    protected function connection(string $url, string $token): Client
    {
        return Client::create($url, ['client' => Client::CLIENT_4X, 'transport' => 'websocket', 'transports' => ['websocket'], 'auth' => ['token' => $token], 'timeout' => 2, 'persistent' => false, 'stream_factory' => BoundedHomebridgeStream::class, 'context' => ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]]);
    }

    public function receive(callable $accept, callable $continue): void
    {
        $provider = app(HomebridgeClient::class);
        $token = $provider->eventToken();
        $client = $this->connection(app(RemoteEventReceiver::class)->url('hb'), $token);
        try {
            $client->connect();
            $client->of('/accessories');
            $client->emit('get-accessories', []);
            while ($continue()) {
                $packet = $client->drain(2);
                if ($packet === null) {
                    continue;
                }
                $normalizer = new HomebridgeClient(now()->toImmutable());
                foreach ($packet->flatten() as $packet) {
                    if ($packet->event === 'accessory-control-failure') {
                        throw new MeasurementException('measurements.errors.providerUnavailable');
                    }
                    if (in_array($packet->event, ['accessories-reload-required', 'matter-accessories-reload-required'], true)) {
                        $client->emit('get-accessories', []);
                    }
                    if ($packet->event !== 'accessories-data') {
                        continue;
                    }
                    $rows = $packet->data;
                    if (! is_array($rows) || count($rows) > 1000) {
                        throw new MeasurementException('measurements.errors.invalidResponse');
                    }
                    foreach (array_is_list($rows) ? $rows : [$rows] as $row) {
                        if (! is_array($row)) {
                            throw new MeasurementException('measurements.errors.invalidResponse');
                        }
                        foreach ($normalizer->normalize($row) as $state) {
                            if (! $continue()) {
                                return;
                            }
                            $accept($state);
                        }
                    }
                }
            }
        } catch (UnsuccessfulOperationException $exception) {
            // A namespace auth rejection invalidates the cached session once.
            $provider->eventToken($token);
            throw new MeasurementException('measurements.errors.accessDenied');
        } finally {
            $client->disconnect();
        }
    }
}
