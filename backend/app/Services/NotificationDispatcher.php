<?php

namespace App\Services;

use App\Models\AlertEpisode;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class NotificationDispatcher
{
    public function __construct(private NotificationConfiguration $configuration, private NotificationTransport $transport) {}

    private function eligible(NotificationDelivery $delivery): bool
    {
        $episode = AlertEpisode::find($delivery->alert_episode_id);
        if (! $episode || ($episode->resolved_at !== null && $episode->resolution !== 'recovered')) {
            return false;
        }
        $settings = $this->configuration->settings();
        if (! $settings[$delivery->channel.'_enabled'] || $settings['version'] !== $delivery->settings_version || ! $this->configuration->ready($delivery->channel) || ! hash_equals($delivery->transport_key, $this->configuration->fingerprint($delivery->channel)) || $delivery->created_at->lessThan(now()->subDay())) {
            return false;
        }
        if ($delivery->channel === 'webhook') {
            return true;
        }
        $user = User::find($delivery->user_id);
        $preference = NotificationPreference::find($delivery->user_id);
        if (! $user || ! app(LocationAccess::class)->record($user, $episode) || ! $user->is_active || $user->session_version !== $delivery->user_version || ! $preference || $preference->version !== $delivery->preference_version || ! $preference->{$delivery->channel.'_enabled'}) {
            return false;
        }
        if ($delivery->channel === 'push') {
            $subscription = PushSubscription::find($delivery->push_subscription_id);
            if (! $subscription || $subscription->user_id !== $user->id || $subscription->user_version !== $user->session_version || $subscription->version !== $delivery->subscription_version || ! hash_equals($subscription->vapid_key_hash, hash('sha256', config('alert_notifications.push.public_key')))) {
                return false;
            }
        }

        return true;
    }

    public function dispatch(string $id): string
    {
        $delivery = DB::transaction(function () use ($id) {
            $delivery = NotificationDelivery::whereKey($id)->lockForUpdate()->first();
            if (! $delivery || ! in_array($delivery->status, ['pending', 'sending'], true) || ($delivery->status === 'pending' && $delivery->next_attempt_at->isFuture()) || ($delivery->status === 'sending' && $delivery->lease_until?->isFuture())) {
                return null;
            }
            if (! $this->eligible($delivery)) {
                $delivery->forceFill(['status' => 'cancelled', 'error_code' => 'notifications.errors.cancelled', 'lease_token' => null, 'lease_until' => null])->save();

                return null;
            }
            if ($delivery->event === 'recovered') {
                $opening = NotificationDelivery::where('alert_episode_id', $delivery->alert_episode_id)->where('event', 'opened')->where('channel', $delivery->channel)->where('recipient_key', $delivery->recipient_key)->first();
                if (! $opening || in_array($opening->status, ['failed', 'cancelled'], true)) {
                    $delivery->forceFill(['status' => 'cancelled', 'error_code' => 'notifications.errors.cancelled'])->save();

                    return null;
                }
                if ($opening->status !== 'sent') {
                    $delivery->forceFill(['status' => 'pending', 'next_attempt_at' => now()->addMinute(), 'lease_token' => null, 'lease_until' => null])->save();

                    return null;
                }
            }
            if ($delivery->attempts >= 5) {
                $delivery->forceFill(['status' => 'failed', 'error_code' => 'notifications.errors.deliveryFailed', 'lease_token' => null, 'lease_until' => null])->save();

                return null;
            }
            $delivery->status = 'sending';
            $delivery->attempts++;
            $delivery->lease_token = (string) Str::uuid();
            $delivery->lease_until = now()->addMinutes(2);
            $delivery->save();

            return $delivery;
        });
        if (! $delivery) {
            return 'skipped';
        }
        if (! $this->eligible($delivery)) {
            $result = null;
        } else {
            try {
                $result = $this->transport->send($delivery);
            } catch (Throwable) {
                $result = new NotificationResult(false, true);
            }
        }

        return DB::transaction(function () use ($delivery, $result) {
            $current = NotificationDelivery::whereKey($delivery->id)->lockForUpdate()->first();
            if (! $current || $current->status !== 'sending' || $current->lease_token !== $delivery->lease_token) {
                return 'skipped';
            }
            if ($result?->expired && $current->push_subscription_id) {
                PushSubscription::whereKey($current->push_subscription_id)->delete();
            }
            $status = $result === null ? 'cancelled' : ($result->success ? 'sent' : ($result->retryable && $current->attempts < 5 ? 'pending' : 'failed'));
            $current->forceFill(['status' => $status, 'sent_at' => $status === 'sent' ? now() : null, 'error_code' => $status === 'sent' ? null : ($status === 'cancelled' ? 'notifications.errors.cancelled' : ($result?->errorCode ?? ($result?->expired ? 'notifications.errors.subscriptionExpired' : 'notifications.errors.deliveryFailed'))), 'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => now()->addSeconds([1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 3600][$current->attempts])])->save();
            if ($status === 'sent' && $current->push_subscription_id) {
                PushSubscription::whereKey($current->push_subscription_id)->update(['last_used_at' => now()]);
            }
            Log::notice('notification.delivery_processed', ['delivery_uuid' => $current->id, 'channel' => $current->channel, 'status' => $status, 'attempts' => $current->attempts]);

            return $status;
        });
    }
}
