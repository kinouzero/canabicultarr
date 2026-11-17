<?php

namespace Tests\Feature;

use App\Exceptions\MeasurementException;
use App\Services\BluetoothRadio;
use App\Services\WebhookDnsResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

class BluetoothRelayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['bluetooth.enabled' => true, 'bluetooth.relay_url' => 'http://127.0.0.1:8766',
            'bluetooth.relay_token' => 'fictional-private-relay-token-for-testing', 'bluetooth.relay_allow_http' => true]);
        Http::preventStrayRequests();
        Process::fake();
    }

    public function test_native_relay_is_authenticated_and_does_not_start_a_container_radio_process(): void
    {
        Http::fake(['http://127.0.0.1:8766/bluetooth' => Http::response(['ok' => true, 'data' => []])]);
        $radio = new BluetoothRadio;
        $this->assertSame('relay', $radio->status()['transport']);
        $this->assertSame([], $radio->perform('discover'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fictional-private-relay-token-for-testing')
            && $request['action'] === 'discover' && $request['adapter'] === 'hci0');
        Process::assertNothingRan();
    }

    public function test_unapproved_destinations_and_plaintext_without_opt_in_are_rejected_before_http(): void
    {
        foreach (['http://169.254.169.254', 'http://8.8.8.8', 'http://100.100.100.200', 'http://[fd00:ec2::254]', 'http://user:password@127.0.0.1', 'http://127.0.0.1/path', 'http://127.0.0.1?token=secret'] as $url) {
            config(['bluetooth.relay_url' => $url]);
            try {
                (new BluetoothRadio)->perform('discover');
                $this->fail('Expected a blocked relay URL.');
            } catch (MeasurementException $error) {
                $this->assertSame('bluetooth.errors.relayConfiguration', $error->errorCode);
            }
        }
        config(['bluetooth.relay_url' => 'http://127.0.0.1:8766', 'bluetooth.relay_allow_http' => false]);
        try {
            (new BluetoothRadio)->perform('discover');
            $this->fail('Expected TLS enforcement.');
        } catch (MeasurementException $error) {
            $this->assertSame('bluetooth.errors.relayConfiguration', $error->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_forced_relay_mode_with_missing_url_never_falls_back_to_the_container_radio(): void
    {
        config(['bluetooth.transport' => 'relay', 'bluetooth.relay_url' => '']);
        $this->expectException(MeasurementException::class);
        $this->expectExceptionMessage('bluetooth.errors.relayConfiguration');
        try {
            (new BluetoothRadio)->perform('discover');
        } finally {
            Process::assertNothingRan();
            Http::assertNothingSent();
        }
    }

    public function test_mixed_private_and_metadata_dns_answers_are_rejected(): void
    {
        config(['bluetooth.relay_url' => 'https://native.example.test:8766']);
        $resolver = Mockery::mock(WebhookDnsResolver::class);
        $resolver->shouldReceive('addresses')->once()->with('native.example.test')->andReturn(['192.168.1.5', '169.254.169.254']);
        $this->app->instance(WebhookDnsResolver::class, $resolver);
        $this->expectException(MeasurementException::class);
        $this->expectExceptionMessage('bluetooth.errors.relayConfiguration');
        try {
            (new BluetoothRadio)->perform('discover');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_redirects_and_oversized_responses_do_not_leak_or_follow_the_relay_token(): void
    {
        Http::fakeSequence()->push('', 302, ['Location' => 'http://169.254.169.254'])->push(str_repeat('x', 262145), 200);
        try {
            (new BluetoothRadio)->perform('discover');
            $this->fail('Expected redirect rejection.');
        } catch (MeasurementException $error) {
            $this->assertSame('bluetooth.errors.unavailable', $error->errorCode);
        }
        Http::assertSentCount(1);
        $this->expectException(MeasurementException::class);
        $this->expectExceptionMessage('bluetooth.errors.invalidReading');
        (new BluetoothRadio)->perform('discover');
    }
}
