<?php

namespace Tests\Feature;

use App\Exceptions\MeasurementException;
use App\Services\BoundedHomebridgeStream;
use App\Services\HomeAssistantEventStream;
use App\Services\HomebridgeEventStream;
use ElephantIO\Client as SocketIoClient;
use ElephantIO\Engine\Packet;
use Mockery;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use WebSocket\Client as WebSocketClient;
use WebSocket\Message\Text;

class ProviderEventTransportTest extends TestCase
{
    public function test_home_assistant_authenticates_and_subscribes_before_forwarding_states(): void
    {
        config(['home_assistant.url' => 'https://ha.example.test', 'home_assistant.token' => 'fictional-token']);
        $state = ['entity_id' => 'sensor.temperature', 'state' => '22'];
        $socket = Mockery::mock(WebSocketClient::class);
        $socket->shouldReceive('receive')->times(4)->andReturn(...array_map(fn ($data) => new Text(json_encode($data)), [
            ['type' => 'auth_required'], ['type' => 'auth_ok'], ['id' => 1, 'type' => 'result', 'success' => true],
            ['id' => 1, 'type' => 'event', 'event' => ['event_type' => 'state_changed', 'data' => ['entity_id' => 'sensor.temperature', 'new_state' => $state]]],
        ]));
        $socket->shouldReceive('text')->once()->with('{"type":"auth","access_token":"fictional-token"}');
        $socket->shouldReceive('text')->once()->with('{"id":1,"type":"subscribe_events","event_type":"state_changed"}');
        $socket->shouldReceive('disconnect')->once();
        $stream = new class($socket) extends HomeAssistantEventStream
        {
            public function __construct(private WebSocketClient $socket) {}

            protected function connection(string $url): WebSocketClient
            {
                Assert::assertSame('wss://ha.example.test/api/websocket', $url);

                return $this->socket;
            }
        };
        $states = [];
        $steps = 0;
        $stream->receive(function ($state) use (&$states) {
            $states[] = $state;
        }, function () use (&$steps) {
            return ++$steps <= 4;
        });
        $this->assertSame([$state], $states);
    }

    public function test_home_assistant_auth_rejection_closes_without_emitting_states(): void
    {
        config(['home_assistant.url' => 'https://ha.example.test']);
        $socket = Mockery::mock(WebSocketClient::class);
        $socket->shouldReceive('receive')->once()->andReturn(new Text('{"type":"auth_invalid"}'));
        $socket->shouldReceive('disconnect')->once();
        $stream = new class($socket) extends HomeAssistantEventStream
        {
            public function __construct(private WebSocketClient $socket) {}

            protected function connection(string $url): WebSocketClient
            {
                return $this->socket;
            }
        };
        $this->expectException(MeasurementException::class);
        $stream->receive(fn () => $this->fail('No unauthenticated state may be emitted.'), fn () => true);
    }

    public function test_homebridge_uses_auth_body_and_normalizes_batched_accessory_updates(): void
    {
        config(['homebridge.url' => 'https://hb.example.test']);
        $this->configureHomebridge('https://hb.example.test', 'fictional-token');
        $service = ['uniqueId' => str_repeat('a', 64), 'serviceName' => 'Probe', 'serviceCharacteristics' => [['iid' => 10, 'type' => 'CurrentTemperature', 'format' => 'float', 'value' => 23.25, 'unit' => 'celsius', 'canRead' => true]]];
        $one = Packet::create(['event' => 'accessories-data'])->setArgs([$service]);
        $two = Packet::create(['event' => 'accessories-data'])->setArgs([[...$service, 'uniqueId' => str_repeat('b', 64)]]);
        $one->next = [$two];
        $socket = Mockery::mock(SocketIoClient::class);
        $socket->shouldReceive('connect')->once();
        $socket->shouldReceive('of')->once()->with('/accessories');
        $socket->shouldReceive('emit')->once()->with('get-accessories', []);
        $socket->shouldReceive('drain')->once()->with(2)->andReturn($one);
        $socket->shouldReceive('disconnect')->once();
        $stream = new class($socket) extends HomebridgeEventStream
        {
            public function __construct(private SocketIoClient $socket) {}

            protected function connection(string $url, string $token): SocketIoClient
            {
                Assert::assertSame('https://hb.example.test', $url);
                Assert::assertSame('fictional-token', $token);

                return $this->socket;
            }
        };
        $states = [];
        $stream->receive(function ($state) use (&$states) {
            $states[] = $state;
        }, function () use (&$states) {
            return count($states) < 2;
        });
        $this->assertCount(2, $states);
        $this->assertSame('23.25', $states[0]['state']);
        $this->assertSame('°C', $states[0]['attributes']['unit_of_measurement']);
        $this->assertSame(str_repeat('b', 64).':10', $states[1]['entity_id']);
        $this->assertSame($states[0]['last_reported'], $states[1]['last_reported']);
    }

    public function test_real_socketio_factory_keeps_credentials_out_of_url_and_enforces_stream_guards(): void
    {
        $factory = new class extends HomebridgeEventStream
        {
            public function create(): SocketIoClient
            {
                return $this->connection('https://hb.example.test', 'fictional-token');
            }
        };
        $client = $factory->create();
        $engine = $client->getEngine();
        $this->assertSame('https://hb.example.test', $engine->getUrl());
        $this->assertSame(['token' => 'fictional-token'], $engine->getOptions()->auth);
        $this->assertSame(BoundedHomebridgeStream::class, $engine->getOptions()->stream_factory);
        $this->assertFalse($engine->getOptions()->persistent);
    }

    public function test_homebridge_rejects_oversized_frame_before_allocating_it(): void
    {
        $stream = new class extends BoundedHomebridgeStream
        {
            public function __construct() {}
        };
        $this->expectException(\RuntimeException::class);
        $stream->read(1048577);
    }

    private function boundedStream($handle): BoundedHomebridgeStream
    {
        return new class($handle) extends BoundedHomebridgeStream
        {
            public function __construct($handle)
            {
                $this->handle = $handle;
            }
        };
    }

    public function test_homebridge_reads_bounded_handshake_lines_and_fixed_frames(): void
    {
        $handle = tmpfile();
        fwrite($handle, "HTTP/1.1 101 Switching Protocols\r\nframe");
        rewind($handle);
        $stream = $this->boundedStream($handle);
        $this->assertSame("HTTP/1.1 101 Switching Protocols\r\n", $stream->read());
        $this->assertSame('frame', $stream->read(5));
        fclose($handle);
        $this->assertNull($stream->read());
    }

    public function test_homebridge_rejects_unterminated_handshake_lines_without_reading_the_rest(): void
    {
        $handle = tmpfile();
        fwrite($handle, str_repeat('x', 16384));
        rewind($handle);
        $stream = $this->boundedStream($handle);
        try {
            $stream->read();
            $this->fail('An oversized handshake line must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Provider event stream limit reached.', $error->getMessage());
            $this->assertSame(8192, ftell($handle));
        } finally {
            fclose($handle);
        }
    }

    public function test_homebridge_enforces_the_aggregate_session_limit_before_the_next_read(): void
    {
        $handle = tmpfile();
        fwrite($handle, str_repeat('x', 4194305));
        rewind($handle);
        $stream = $this->boundedStream($handle);
        try {
            for ($i = 0; $i < 4; $i++) {
                $this->assertSame(1048576, strlen($stream->read(1048576)));
            }
            $stream->read(1);
            $this->fail('A session must stop at its aggregate byte limit.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Provider event stream limit reached.', $error->getMessage());
            $this->assertSame(4194304, ftell($handle));
        } finally {
            fclose($handle);
        }
    }
}
