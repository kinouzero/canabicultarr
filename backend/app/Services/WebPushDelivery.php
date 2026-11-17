<?php

namespace App\Services;

use App\Models\NotificationDelivery;
use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;

class WebPushDelivery
{
    protected function client(): ClientInterface
    {
        return new Client(['connect_timeout' => 3, 'timeout' => 8, 'allow_redirects' => false, 'http_errors' => false]);
    }

    public function send(PushSubscription $subscription, NotificationDelivery $delivery): NotificationResult
    {
        if (! app(PushEndpoint::class)->allowed($subscription->endpoint)) {
            return new NotificationResult(false);
        }
        $config = config('alert_notifications.push');
        $client = $this->client();
        $push = new WebPush(['VAPID' => ['subject' => $config['subject'], 'publicKey' => $config['public_key'], 'privateKey' => $config['private_key']]], ['TTL' => 300, 'urgency' => 'normal'], client: $client, logger: new NullLogger);
        // Deliberately generic lock-screen content; never send names or readings.
        $payload = json_encode(['event' => $delivery->event, 'locale' => $delivery->payload['locale'], 'id' => $delivery->id], JSON_THROW_ON_ERROR);
        $report = $push->sendOneNotification(Subscription::create(['endpoint' => $subscription->endpoint, 'keys' => $subscription->keys, 'contentEncoding' => 'aes128gcm']), $payload);
        $status = $report->getResponse()?->getStatusCode();

        return new NotificationResult($report->isSuccess(), $status === null || $status === 408 || $status === 429 || $status >= 500, $report->isSubscriptionExpired());
    }
}
