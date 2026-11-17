<?php

namespace App\Services;

use App\Exceptions\InstanceSettingsException;
use App\Models\NotificationDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use Throwable;

class ConnectionCheck
{
    public function run(string $group, User $actor, string $sessionId, string $locale): string
    {
        try {
            return match ($group) {
                'storage' => $this->storage(),
                'home_assistant' => $this->remote(app(HomeAssistantClient::class)),
                'homebridge' => $this->remote(app(HomebridgeClient::class)),
                'mqtt' => $this->mqtt(),
                'oidc' => $this->oidc(),
                'mail', 'webhook', 'push' => $this->notification($group, $actor, $sessionId, $locale),
                default => throw new InstanceSettingsException('connectionSettings.checkFailed', 422),
            };
        } catch (InstanceSettingsException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Remote errors may contain passwords, tokens or private endpoint URLs.
            throw new InstanceSettingsException('connectionSettings.checkFailed', 503);
        }
    }

    private function storage(): string
    {
        if (config('documents.disk') === 'documents') {
            $root = config('filesystems.disks.documents.root');
            if (! is_string($root) || ! is_dir($root) || ! is_readable($root) || ! is_writable($root)) {
                throw new InstanceSettingsException('connectionSettings.checkFailed', 503);
            }
        } else {
            $settings = config('filesystems.disks.documents_s3');
            $client = new S3Client(['version' => 'latest', 'region' => $settings['region'],
                'endpoint' => $settings['endpoint'], 'use_path_style_endpoint' => $settings['use_path_style_endpoint'],
                'credentials' => ['key' => $settings['key'], 'secret' => $settings['secret']],
                'http' => ['connect_timeout' => 2, 'timeout' => 5, 'allow_redirects' => false], 'retries' => 0]);
            $client->headBucket(['Bucket' => $settings['bucket']]);
        }

        return 'connectionSettings.connected';
    }

    private function remote(RemoteSensorClient $client): string
    {
        $client->check();

        return 'connectionSettings.connected';
    }

    private function mqtt(): string
    {
        $configuration = app(MqttConfiguration::class);
        if (! $configuration->ready()) {
            throw new InstanceSettingsException('connectionSettings.notConfigured', 422);
        }
        $client = $configuration->client();
        try {
            // A unique clean session tests authentication without subscribing or publishing.
            $client->connect($configuration->connectionSettings(), true);
        } finally {
            if ($client->isConnected()) {
                $client->disconnect();
            }
        }

        return 'connectionSettings.connected';
    }

    private function oidc(): string
    {
        if (! app(OidcClient::class)->enabled()) {
            throw new InstanceSettingsException('connectionSettings.notConfigured', 422);
        }
        $issuer = config('oidc.issuer');
        $response = Http::acceptJson()->connectTimeout(2)->timeout(5)->withoutRedirecting()
            ->withOptions(['stream' => true])->get(rtrim($issuer, '/').'/.well-known/openid-configuration');
        try {
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof() && strlen($body) <= 262144) {
                $body .= $stream->read(min(65536, 262145 - strlen($body)));
            }
            $discovery = json_decode($body, true);
            if (! $response->successful() || strlen($body) > 262144 || ! is_array($discovery)
                || ($discovery['issuer'] ?? null) !== $issuer) {
                throw new InstanceSettingsException('connectionSettings.checkFailed', 503);
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
                $url = $discovery[$key] ?? null;
                if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new InstanceSettingsException('connectionSettings.checkFailed', 503);
                }
            }
        } finally {
            $response->close();
        }

        // Client credentials and the callback are verified by the interactive login flow.
        return 'connectionSettings.oidcChecked';
    }

    private function notification(string $group, User $actor, string $sessionId, string $locale): string
    {
        $channel = $group === 'mail' ? 'email' : $group;
        if (! app(NotificationConfiguration::class)->ready($channel)) {
            throw new InstanceSettingsException('connectionSettings.notConfigured', 422);
        }
        $delivery = new NotificationDelivery(['id' => (string) Str::uuid(), 'event' => 'test',
            'channel' => $channel, 'user_id' => $actor->id,
            'payload' => ['locale' => $locale, 'title' => __('connection_checks.title', [], $locale),
                'message' => __('connection_checks.message', [], $locale), 'occurred_at' => now()->toISOString(), 'alert' => null]]);
        if ($channel === 'email') {
            // Use the current saved SMTP configuration, even after an in-process update.
            Mail::purge('smtp');
            config(['mail.mailers.smtp.timeout' => 8]);
        }
        if ($channel === 'push') {
            $config = config('alert_notifications.push');
            VAPID::validate(['subject' => $config['subject'], 'publicKey' => $config['public_key'], 'privateKey' => $config['private_key']]);
            $subscription = PushSubscription::where('user_id', $actor->id)->where('user_version', $actor->session_version)
                ->where('session_hash', hash_hmac('sha256', $sessionId, config('app.key')))
                ->where('vapid_key_hash', hash('sha256', $config['public_key']))->latest()->first();
            if (! $subscription) {
                throw new InstanceSettingsException('connectionSettings.pushSubscriptionRequired', 422);
            }
            $delivery->push_subscription_id = $subscription->id;
        }
        $result = app(NotificationTransport::class)->send($delivery);
        if (! $result->success) {
            throw new InstanceSettingsException($result->errorCode === 'notifications.errors.webhookBlocked' ? 'connectionSettings.webhookBlocked' : 'connectionSettings.checkFailed', 503);
        }

        return 'connectionSettings.testSent';
    }
}
