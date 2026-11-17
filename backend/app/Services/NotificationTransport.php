<?php

namespace App\Services;

use App\Exceptions\WebhookDestinationException;
use App\Mail\MonitoringAlertMail;
use App\Models\NotificationDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotificationTransport
{
    public function __construct(private WebPushDelivery $push) {}

    public function send(NotificationDelivery $delivery): NotificationResult
    {
        $payload = $delivery->payload;
        if ($delivery->channel === 'email') {
            $user = User::find($delivery->user_id);
            if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                return new NotificationResult(false);
            }
            Mail::mailer('smtp')->to($user->email)->send(
                (new MonitoringAlertMail($delivery->id, $payload['title'], $payload['message']))->locale($payload['locale'] ?? 'en')
            );

            return new NotificationResult(true);
        }
        if ($delivery->channel === 'push') {
            $subscription = PushSubscription::find($delivery->push_subscription_id);

            return $subscription ? $this->push->send($subscription, $delivery) : new NotificationResult(false, expired: true);
        }
        $config = config('alert_notifications.webhook');
        try {
            $options = app(WebhookDestination::class)->options($config['url']);
        } catch (WebhookDestinationException $exception) {
            return new NotificationResult(false, $exception->retryable, errorCode: $exception->retryable ? null : 'notifications.errors.webhookBlocked');
        }
        $body = match ($config['format']) {
            'ntfy' => ['topic' => $config['topic'], 'title' => $payload['title'], 'message' => $payload['message']],
            'apprise' => array_filter(['title' => $payload['title'], 'body' => $payload['message'], 'format' => 'text', 'tag' => $config['tag'], 'urls' => $config['apprise_urls']], fn ($v) => $v !== ''),
            default => ['schema_version' => 1, 'event_id' => $delivery->id, 'event' => 'alert.'.$delivery->event, 'occurred_at' => $payload['occurred_at'], 'title' => $payload['title'], 'message' => $payload['message'], 'alert' => $payload['alert']],
        };
        // Only the status is needed; discard response bytes through Guzzle's sink.
        $options['sink'] = FnStream::decorate(Utils::streamFor(''), ['write' => static fn (string $chunk): int => strlen($chunk)]);
        $request = Http::acceptJson()->setHandler(new CurlHandler)->withHeaders(['Idempotency-Key' => $delivery->id])->connectTimeout(3)->timeout(8)->withoutRedirecting()->withOptions($options);
        if (filled($config['token'])) {
            $request = $request->withToken($config['token']);
        }
        $response = $request->post($config['url'], $body);
        try {
            $status = $response->status();

            return new NotificationResult($response->successful(), $status === 408 || $status === 429 || $status >= 500);
        } finally {
            $response->close();
        }
    }
}
