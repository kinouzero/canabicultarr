<?php

namespace App\Services;

use App\Models\AlertEpisode;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;

class NotificationOutbox
{
    public function __construct(private NotificationConfiguration $configuration) {}

    public function enqueue(AlertEpisode $episode, string $event): void
    {
        $settings = $this->configuration->settings();
        $rank = ['info' => 0, 'warning' => 1, 'critical' => 2];
        if (! in_array($event, ['opened', 'recovered'], true) || ($rank[$episode->severity] ?? -1) < ($rank[$settings['minimum_severity']] ?? 0) || ($event === 'recovered' && ! $settings['include_recovery'])) {
            return;
        }
        if (! $settings['email_enabled'] && ! $settings['push_enabled'] && ! $settings['webhook_enabled']) {
            return;
        }
        $add = function (string $channel, string $recipient, ?User $user, ?NotificationPreference $preference, ?PushSubscription $subscription, string $locale) use ($episode, $event, $settings) {
            if ($user && ! app(LocationAccess::class)->record($user, $episode)) {
                return;
            }
            if (! $settings[$channel.'_enabled'] || ! $this->configuration->ready($channel)) {
                return;
            }
            if ($event === 'recovered' && ! NotificationDelivery::where('alert_episode_id', $episode->id)->where('event', 'opened')->where('channel', $channel)->where('recipient_key', $recipient)->where('settings_version', $settings['version'])->whereIn('status', ['pending', 'sending', 'sent'])->exists()) {
                return;
            }
            $rule = $episode->rule_snapshot;
            $clean = fn ($value) => mb_substr(preg_replace('/[\p{C}]+/u', ' ', (string) $value) ?? '', 0, 120);
            $title = 'Canabicultarr — '.__('notifications.'.$event, [], $locale);
            $time = ($event === 'opened' ? $episode->opened_at : $episode->resolved_at)->utc()->toISOString();
            $message = implode("\n", [__('notifications.rule', [], $locale).': '.$clean($rule['name'] ?? ''), __('notifications.sensor', [], $locale).': '.$clean($rule['sensor']['name'] ?? ''), __('notifications.severity', [], $locale).': '.$episode->severity, __('notifications.time', [], $locale).': '.$time]);
            NotificationDelivery::firstOrCreate(['alert_episode_id' => $episode->id, 'event' => $event, 'channel' => $channel, 'recipient_key' => $recipient], [
                'user_id' => $user?->id, 'push_subscription_id' => $subscription?->id,
                'settings_version' => $settings['version'], 'transport_key' => $this->configuration->fingerprint($channel),
                'preference_version' => $preference?->version, 'user_version' => $user?->session_version, 'subscription_version' => $subscription?->version,
                'payload' => ['locale' => $locale, 'title' => $title, 'message' => $message, 'occurred_at' => $time, 'alert' => ['id' => $episode->id, 'rule_id' => $episode->alert_rule_id, 'severity' => $episode->severity, 'reason' => $event === 'opened' ? $episode->reason : 'recovered']],
                'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(),
            ]);
        };
        if ($settings['webhook_enabled']) {
            $add('webhook', 'installation', null, null, null, $settings['locale']);
        }
        if (! $settings['email_enabled'] && ! $settings['push_enabled']) {
            return;
        }
        NotificationPreference::where(fn ($q) => $q->where('email_enabled', true)->orWhere('push_enabled', true))->chunkById(100, function ($preferences) use ($add) {
            $users = User::whereIn('id', $preferences->pluck('user_id'))->where('is_active', true)->get()->keyBy('id');
            foreach ($preferences as $preference) {
                $user = $users->get($preference->user_id);
                if (! $user) {
                    continue;
                }
                if ($preference->email_enabled) {
                    $add('email', 'user:'.$user->uuid, $user, $preference, null, $preference->locale);
                }
                if ($preference->push_enabled) {
                    foreach (PushSubscription::where('user_id', $user->id)->where('user_version', $user->session_version)->where('vapid_key_hash', hash('sha256', config('alert_notifications.push.public_key')))->get() as $subscription) {
                        $add('push', 'subscription:'.$subscription->id, $user, $preference, $subscription, $preference->locale);
                    }
                }
            }
        }, 'user_id');
    }
}
