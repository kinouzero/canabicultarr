<?php

namespace App\Services;

use App\Exceptions\MeasurementException;
use WebSocket\Client;
use WebSocket\Configuration;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Text;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

class HomeAssistantEventStream
{
    protected function connection(string $url): Client
    {
        return (new Client($url, new Configuration(timeout: 2, maxReceivedFrameSize: 1048576, maxReceivedMessageSize: 1048576)))
            ->addMiddleware(new CloseHandler)->addMiddleware(new PingResponder);
    }

    public function receive(callable $accept, callable $continue): void
    {
        $url = app(RemoteEventReceiver::class)->url('ha');
        $client = $this->connection(preg_replace('/^http/', 'ws', $url).'/api/websocket');
        $authenticated = false;
        $subscribed = false;
        $handshakeUntil = microtime(true) + 5;
        try {
            while ($continue()) {
                if (! $subscribed && microtime(true) > $handshakeUntil) {
                    throw new MeasurementException('measurements.errors.providerUnavailable');
                }
                try {
                    $message = $client->receive();
                } catch (ConnectionTimeoutException) {
                    if (! $subscribed && microtime(true) > $handshakeUntil) {
                        throw new MeasurementException('measurements.errors.providerUnavailable');
                    }

                    continue;
                }
                if (! $message instanceof Text) {
                    continue;
                }
                $data = json_decode($message->getContent(), true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($data)) {
                    throw new MeasurementException('measurements.errors.invalidResponse');
                }
                if (($data['type'] ?? null) === 'auth_required' && ! $authenticated) {
                    $client->text(json_encode(['type' => 'auth', 'access_token' => config('home_assistant.token')], JSON_THROW_ON_ERROR));
                } elseif (($data['type'] ?? null) === 'auth_ok' && ! $authenticated) {
                    $authenticated = true;
                    $client->text('{"id":1,"type":"subscribe_events","event_type":"state_changed"}');
                } elseif (($data['type'] ?? null) === 'auth_invalid' || (($data['type'] ?? null) === 'result' && ($data['id'] ?? null) === 1 && ($data['success'] ?? null) !== true)) {
                    throw new MeasurementException('measurements.errors.accessDenied');
                } elseif ($authenticated && ($data['type'] ?? null) === 'result' && ($data['id'] ?? null) === 1 && ($data['success'] ?? null) === true) {
                    $subscribed = true;
                } elseif ($subscribed && ($data['type'] ?? null) === 'event' && ($data['id'] ?? null) === 1 && ($data['event']['event_type'] ?? null) === 'state_changed') {
                    $state = $data['event']['data']['new_state'] ?? null;
                    if (is_array($state) && ($state['entity_id'] ?? null) === ($data['event']['data']['entity_id'] ?? null)) {
                        $accept($state);
                    }
                }
            }
        } finally {
            $client->disconnect();
        }
    }
}
