<?php

namespace Tests\Feature;

use App\Exceptions\WebhookDestinationException;
use App\Models\NotificationDelivery;
use App\Services\NotificationTransport;
use App\Services\WebhookDestination;
use App\Services\WebhookDnsResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookDestinationTest extends TestCase
{
    public function test_public_dns_is_pinned_and_proxy_and_response_buffering_are_disabled(): void
    {
        $this->mock(WebhookDnsResolver::class)->shouldReceive('addresses')->once()->with('notify.example.test')->andReturn(['93.184.216.34', '2606:4700:4700::1111']);
        $options = app(WebhookDestination::class)->options('https://notify.example.test:8443/hook');
        $this->assertSame('', $options['proxy']);
        $this->assertSame(['notify.example.test:8443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('v4', $options['force_ip_resolve']);
    }

    public function test_malformed_urls_and_sensitive_ip_literals_fail_before_network_io_even_with_broad_exceptions(): void
    {
        config(['alert_notifications.webhook.allowed_internal' => '0.0.0.0/0,::/0,localhost,169.254.169.254']);
        Http::preventStrayRequests();
        $policy = app(WebhookDestination::class);
        foreach (['http://2130706433', 'http://0177.0.0.1', 'http://0x7f000001', 'http://example.test:0', 'file:///etc/passwd', 'http://user:pass@example.test', "http://example.test\n", 'http://example.test/#fragment', 'http://example.test\\@127.0.0.1', 'http://127.0.0.1', 'http://169.254.169.254/latest/meta-data', 'http://100.100.100.200', 'http://168.63.129.16', 'http://224.0.0.1', 'http://0.0.0.0', 'http://192.0.2.1', 'http://[::1]', 'http://[::ffff:127.0.0.1]', 'http://[64:ff9b::7f00:1]', 'http://[fe80::1]', 'http://[ff02::1]'] as $url) {
            try {
                $policy->options($url);
                $this->fail('Unsafe URL passed destination policy.');
            } catch (WebhookDestinationException $exception) {
                $this->assertFalse($exception->retryable);
            }
        }
        Http::assertNothingSent();
    }

    public function test_mixed_dns_and_rebinding_are_rechecked_before_every_send(): void
    {
        $resolver = $this->mock(WebhookDnsResolver::class);
        $resolver->shouldReceive('addresses')->with('notify.example.test')->times(3)->andReturn(['93.184.216.34'], ['127.0.0.1'], ['93.184.216.34', 'fd00::1']);
        $policy = app(WebhookDestination::class);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $policy->options('https://notify.example.test/hook')['curl']);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $policy->options('https://notify.example.test/hook');
                $this->fail('DNS change passed destination policy.');
            } catch (WebhookDestinationException $exception) {
                $this->assertFalse($exception->retryable);
            }
        }
    }

    public function test_only_explicit_lan_hosts_and_networks_are_allowed_and_ipv6_is_pinned(): void
    {
        $this->mock(WebhookDnsResolver::class)->shouldReceive('addresses')->with('ntfy.lan')->andReturn(['192.168.10.9']);
        $policy = app(WebhookDestination::class);
        foreach (['ntfy.lan', '192.168.10.0/24', '192.168.10.9'] as $allow) {
            config(['alert_notifications.webhook.allowed_internal' => $allow]);
            $this->assertSame(['ntfy.lan:80:192.168.10.9'], $policy->options('http://ntfy.lan/hook')['curl'][CURLOPT_RESOLVE]);
        }
        config(['alert_notifications.webhook.allowed_internal' => 'fd12:3456::/32']);
        $this->mock(WebhookDnsResolver::class)->shouldReceive('addresses')->with('apprise.lan')->andReturn(['fd12:3456::9']);
        $this->assertSame(['apprise.lan:443:[fd12:3456::9]'], app(WebhookDestination::class)->options('https://apprise.lan/notify')['curl'][CURLOPT_RESOLVE]);
        config(['alert_notifications.webhook.allowed_internal' => 'ntfy.lan.attacker.test']);
        $this->expectException(WebhookDestinationException::class);
        $policy->options('http://ntfy.lan/hook');
    }

    public function test_invalid_exception_ranges_are_rejected_and_empty_dns_is_retryable(): void
    {
        $policy = app(WebhookDestination::class);
        foreach (['*', '*.lan', '0.0.0.0/0', '::/0', '10.0.0.0/7', '192.168.1.0/33', 'fd00::/129', '10.0.0.1/not-a-prefix', 'https://ntfy.lan', str_repeat('a', 2049)] as $entry) {
            $this->assertFalse($policy->validInternalList($entry), $entry);
        }
        $this->assertTrue($policy->validInternalList('ntfy.lan,10.1.0.0/16,fd12::/32'));
        $this->mock(WebhookDnsResolver::class)->shouldReceive('addresses')->with('offline.example.test')->andReturn([]);
        try {
            app(WebhookDestination::class)->options('https://offline.example.test');
            $this->fail('Empty DNS passed destination policy.');
        } catch (WebhookDestinationException $exception) {
            $this->assertTrue($exception->retryable);
        }
    }

    public function test_real_curl_transport_uses_the_pinned_address_and_does_not_follow_redirects(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        $this->assertIsResource($listener);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $directory = sys_get_temp_dir().'/webhook-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $router = $directory.'/router.php';
        $requests = $directory.'/requests';
        file_put_contents($router, '<?php file_put_contents(__DIR__."/requests", $_SERVER["HTTP_HOST"]."\\n", FILE_APPEND); http_response_code(302); header("Location: http://127.0.0.1:1/never-contact"); echo str_repeat("discard", 131072);');
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, $router], [0 => ['pipe', 'r'], 1 => ['file', $directory.'/server.log', 'a'], 2 => ['file', $directory.'/server.log', 'a']], $pipes);
        $this->assertIsResource($process);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $error, $message, .02);
                if ($socket) {
                    fclose($socket);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($ready, 'Isolated HTTP fixture did not start.');
            // The policy itself rejects loopback. This transport regression supplies
            // its already-validated options directly, so no external service is used.
            $this->mock(WebhookDestination::class)->shouldReceive('options')->once()->andReturn([
                'proxy' => '', 'force_ip_resolve' => 'v4',
                'curl' => [CURLOPT_RESOLVE => ['fixture.invalid:'.$port.':127.0.0.1']],
            ]);
            config(['alert_notifications.webhook' => ['url' => 'http://fixture.invalid:'.$port.'/', 'format' => 'ntfy', 'token' => '', 'topic' => 'fixture']]);
            $delivery = new NotificationDelivery(['id' => 'fixture', 'channel' => 'webhook', 'payload' => ['title' => 'Alert', 'message' => 'Test']]);
            $result = app(NotificationTransport::class)->send($delivery);
            $this->assertFalse($result->success);
            $this->assertFalse($result->retryable);
            $this->assertSame('fixture.invalid:'.$port."\n", file_get_contents($requests));
        } finally {
            fclose($pipes[0]);
            proc_terminate($process);
            proc_close($process);
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_blocked_transport_does_not_send_but_approved_transport_keeps_payload_and_credentials(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) {
            $this->assertSame(['ntfy.lan:80:192.168.10.9'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(1024, $options['sink']->write(str_repeat('a', 1024)));
            $this->assertSame(0, $options['sink']->getSize());
            $this->assertSame('Bearer fixture', $request->header('Authorization')[0]);
            $this->assertSame('water-alerts', $request['topic']);

            return Http::response('', 202);
        });
        config(['alert_notifications.webhook' => ['url' => 'http://ntfy.lan/', 'format' => 'ntfy', 'token' => 'fixture', 'topic' => 'water-alerts']]);
        $this->mock(WebhookDnsResolver::class)->shouldReceive('addresses')->with('ntfy.lan')->twice()->andReturn(['192.168.10.9']);
        $delivery = new NotificationDelivery(['id' => 'fixture', 'channel' => 'webhook', 'payload' => ['title' => 'Alert', 'message' => 'Test']]);
        $result = app(NotificationTransport::class)->send($delivery);
        $this->assertFalse($result->success);
        $this->assertFalse($result->retryable);
        $this->assertSame('notifications.errors.webhookBlocked', $result->errorCode);
        Http::assertNothingSent();
        config(['alert_notifications.webhook.allowed_internal' => 'ntfy.lan']);
        $this->assertTrue(app(NotificationTransport::class)->send($delivery)->success);
        Http::assertSentCount(1);
    }
}
