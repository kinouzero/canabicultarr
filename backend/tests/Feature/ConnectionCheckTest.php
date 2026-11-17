<?php

namespace Tests\Feature;

use App\Exceptions\InstanceSettingsException;
use App\Mail\MonitoringAlertMail;
use App\Models\NotificationDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\ConnectionCheck;
use App\Services\HomeAssistantClient;
use App\Services\HomebridgeClient;
use App\Services\MqttConfiguration;
use App\Services\NotificationResult;
use App\Services\NotificationTransport;
use App\Services\WebhookDnsResolver;
use Database\Seeders\AccessProfileSeeder;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Minishlink\WebPush\VAPID;
use Mockery;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Tests\TestCase;

class ConnectionCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_checks_are_admin_only_and_dispatch_every_known_group(): void
    {
        $check = $this->mock(ConnectionCheck::class);
        foreach (array_keys(config('connection_settings')) as $group) {
            $this->postJson("/api/v1/connection-settings/{$group}/check")->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/connection-settings/mail/check')->assertForbidden();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($admin);
        foreach (array_keys(config('connection_settings')) as $group) {
            $check->shouldReceive('run')->once()->with($group, Mockery::on(fn ($actor) => $actor->id === $admin->id), Mockery::type('string'), 'fr')->andReturn('connectionSettings.connected');
            $this->postJson("/api/v1/connection-settings/{$group}/check", ['locale' => 'fr'])->assertOk()->assertJsonPath('data.message', 'connectionSettings.connected');
        }
        $this->postJson('/api/v1/connection-settings/unknown/check')->assertNotFound();
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_remote_checks_reuse_existing_clients_and_hide_provider_exceptions(): void
    {
        $actor = User::factory()->create();
        $this->mock(HomeAssistantClient::class)->shouldReceive('check')->once();
        $this->mock(HomebridgeClient::class)->shouldReceive('check')->once()->andThrow(new \RuntimeException('secret-private-url'));
        $this->assertSame('connectionSettings.connected', app(ConnectionCheck::class)->run('home_assistant', $actor, '', 'en'));
        try {
            app(ConnectionCheck::class)->run('homebridge', $actor, '', 'en');
            $this->fail('Remote failure must not report success.');
        } catch (InstanceSettingsException $error) {
            $this->assertSame('connectionSettings.checkFailed', $error->errorCode);
            $this->assertStringNotContainsString('secret', $error->getMessage());
        }
    }

    public function test_oidc_checks_discovery_without_claiming_client_authentication(): void
    {
        config(['oidc.enabled' => true, 'oidc.issuer' => 'https://identity.example.test/', 'oidc.client_id' => 'fictional', 'oidc.client_secret' => 'private', 'oidc.redirect' => 'http://localhost/callback']);
        Http::preventStrayRequests();
        Http::fake(['https://identity.example.test/*' => Http::response(['issuer' => 'https://identity.example.test/', 'authorization_endpoint' => 'https://identity.example.test/authorize', 'token_endpoint' => 'https://identity.example.test/token', 'jwks_uri' => 'https://identity.example.test/keys'])]);
        $actor = User::factory()->create();
        $this->assertSame('connectionSettings.oidcChecked', app(ConnectionCheck::class)->run('oidc', $actor, '', 'en'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && ! $request->hasHeader('Authorization') && $request->data() === []);
        Http::fake(['*' => Http::response(['issuer' => 'https://wrong.example.test/'])]);
        $this->expectException(InstanceSettingsException::class);
        app(ConnectionCheck::class)->run('oidc', $actor, '', 'en');
    }

    public function test_oidc_discovery_reads_fragmented_streams_and_rejects_oversized_bodies(): void
    {
        config(['oidc.enabled' => true, 'oidc.issuer' => 'https://identity.example.test/', 'oidc.client_id' => 'fictional', 'oidc.client_secret' => 'private', 'oidc.redirect' => 'http://localhost/callback']);
        Http::preventStrayRequests();
        $discovery = ['issuer' => 'https://identity.example.test/', 'authorization_endpoint' => 'https://identity.example.test/authorize', 'token_endpoint' => 'https://identity.example.test/token', 'jwks_uri' => 'https://identity.example.test/keys'];
        $body = Utils::streamFor(json_encode($discovery));
        $fragmented = FnStream::decorate($body, ['read' => fn ($length) => $body->read(min(7, $length))]);
        Http::fake(['*' => Http::response($fragmented)]);
        $actor = User::factory()->create();
        $this->assertSame('connectionSettings.oidcChecked', app(ConnectionCheck::class)->run('oidc', $actor, '', 'en'));

        Http::fake(['*' => Http::response(json_encode([...$discovery, 'padding' => str_repeat('x', 262144)]))]);
        try {
            app(ConnectionCheck::class)->run('oidc', $actor, '', 'en');
            $this->fail('Oversized discovery must not be accepted.');
        } catch (InstanceSettingsException $error) {
            $this->assertSame('connectionSettings.checkFailed', $error->errorCode);
        }
    }

    public function test_mqtt_connects_with_saved_credentials_and_disconnects_without_consuming_data(): void
    {
        $client = Mockery::mock(MqttClient::class);
        $client->shouldReceive('connect')->once()->with(Mockery::type(ConnectionSettings::class), true);
        $client->shouldReceive('isConnected')->once()->andReturn(true);
        $client->shouldReceive('disconnect')->once();
        $configuration = $this->partialMock(MqttConfiguration::class);
        $configuration->shouldReceive('ready')->once()->andReturn(true);
        $configuration->shouldReceive('client')->once()->andReturn($client);
        $this->assertSame('connectionSettings.connected', app(ConnectionCheck::class)->run('mqtt', User::factory()->create(), '', 'en'));
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_email_and_webhook_send_explicit_test_messages_without_creating_alerts(): void
    {
        $this->mock(WebhookDnsResolver::class)->shouldReceive('addresses')->with('notify.example.test')->andReturn(['93.184.216.34']);
        $actor = User::factory()->create();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.example.test', 'mail.from.address' => 'sender@example.test', 'alert_notifications.webhook.url' => 'https://notify.example.test/', 'alert_notifications.webhook.format' => 'generic']);
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['https://notify.example.test/' => Http::response([], 202)]);
        $check = app(ConnectionCheck::class);
        $this->assertSame('connectionSettings.testSent', $check->run('mail', $actor, '', 'fr'));
        Mail::assertSent(MonitoringAlertMail::class, fn ($mail) => $mail->hasTo($actor->email) && str_contains($mail->title, 'Test de connexion'));
        $this->assertSame('connectionSettings.testSent', $check->run('webhook', $actor, '', 'fr'));
        Http::assertSent(fn ($request) => $request['event'] === 'alert.test' && $request['alert'] === null);
        $this->assertDatabaseCount('alert_episodes', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_push_requires_a_subscription_for_the_current_account_session_and_key(): void
    {
        $keys = VAPID::createVapidKeys();
        config(['alert_notifications.push.public_key' => $keys['publicKey'], 'alert_notifications.push.private_key' => $keys['privateKey'], 'alert_notifications.push.subject' => 'mailto:sender@example.test']);
        $actor = User::factory()->create();
        $check = app(ConnectionCheck::class);
        try {
            $check->run('push', $actor, 'current-session', 'en');
            $this->fail('No matching subscription.');
        } catch (InstanceSettingsException $error) {
            $this->assertSame('connectionSettings.pushSubscriptionRequired', $error->errorCode);
        }
        $subscription = new PushSubscription;
        $subscription->forceFill(['user_id' => $actor->id, 'user_version' => $actor->session_version, 'session_hash' => hash_hmac('sha256', 'current-session', config('app.key')), 'vapid_key_hash' => hash('sha256', $keys['publicKey']), 'endpoint' => 'https://fcm.googleapis.com/fictional', 'endpoint_hash' => hash('sha256', 'fictional'), 'keys' => ['p256dh' => 'fictional', 'auth' => 'fictional'], 'version' => 1])->save();
        $this->mock(NotificationTransport::class)->shouldReceive('send')->once()->with(Mockery::on(fn (NotificationDelivery $delivery) => $delivery->event === 'test' && $delivery->push_subscription_id === $subscription->id))->andReturn(new NotificationResult(true));
        $this->assertSame('connectionSettings.testSent', $check->run('push', $actor, 'current-session', 'en'));
    }

    public function test_local_storage_check_is_read_only_and_rejects_unavailable_roots(): void
    {
        config(['documents.disk' => 'documents', 'filesystems.disks.documents.root' => sys_get_temp_dir()]);
        $actor = User::factory()->create();
        $this->assertSame('connectionSettings.connected', app(ConnectionCheck::class)->run('storage', $actor, '', 'en'));
        config(['filesystems.disks.documents.root' => '/nonexistent-fictional-storage']);
        $this->expectException(InstanceSettingsException::class);
        app(ConnectionCheck::class)->run('storage', $actor, '', 'en');
    }

    public function test_incomplete_connections_fail_instead_of_reporting_success(): void
    {
        config(['mqtt.enabled' => false, 'oidc.enabled' => false, 'mail.default' => 'log']);
        foreach (['mqtt', 'oidc', 'mail'] as $group) {
            try {
                app(ConnectionCheck::class)->run($group, User::factory()->create(), '', 'en');
                $this->fail('Unconfigured connection.');
            } catch (InstanceSettingsException $error) {
                $this->assertSame('connectionSettings.notConfigured', $error->errorCode);
            }
        }
    }
}
