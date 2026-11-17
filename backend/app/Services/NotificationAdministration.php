<?php

namespace App\Services;

use App\Exceptions\NotificationException;
use App\Models\AlertEpisode;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NotificationAdministration
{
    public function __construct(private NotificationConfiguration $configuration, private PushEndpoint $endpoints) {}

    public function actor(User $actor, bool $manage = false, bool $lock = false): User
    {
        $query = User::whereKey($actor->id);
        $current = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }
        Gate::forUser($current)->authorize($manage ? 'notifications.manage' : 'notifications.view');

        return $current;
    }

    public function status(User $actor): array
    {
        $current = $this->actor($actor);
        $settings = $this->configuration->settings();

        return ['settings' => $settings, 'canManage' => Gate::forUser($current)->allows('notifications.manage'), 'channels' => [
            'email' => ['configured' => $this->configuration->ready('email')],
            'push' => ['configured' => $this->configuration->ready('push'), 'publicKey' => $this->configuration->ready('push') ? config('alert_notifications.push.public_key') : null],
            'webhook' => ['configured' => $this->configuration->ready('webhook'), 'format' => in_array(config('alert_notifications.webhook.format'), ['generic', 'ntfy', 'apprise'], true) ? config('alert_notifications.webhook.format') : 'generic'],
        ]];
    }

    public function saveSettings(User $actor, array $data): array
    {
        DB::transaction(function () use ($actor, $data) {
            $this->actor($actor, true, true);
            NotificationSetting::insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $settings = NotificationSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($settings->version !== (int) $data['version']) {
                throw new NotificationException('notifications.errors.conflict', 409);
            }
            foreach (['email', 'push', 'webhook'] as $channel) {
                if ($data[$channel.'_enabled'] && ! $settings->{$channel.'_enabled'} && ! $this->configuration->ready($channel)) {
                    throw new NotificationException('notifications.errors.notConfigured');
                }
            }
            $settings->fill(array_intersect_key($data, NotificationConfiguration::DEFAULTS));
            if ($settings->isDirty()) {
                $settings->version++;
                $settings->save();
                Log::notice('notification.settings_changed', ['actor_uuid' => $actor->uuid, 'version' => $settings->version]);
            }
        });

        return $this->status($actor);
    }

    public function preferences(User $actor): array
    {
        $this->actor($actor);
        $preference = NotificationPreference::find($actor->id);

        return $preference ? array_intersect_key($preference->toArray(), NotificationConfiguration::PREFERENCE_DEFAULTS) : NotificationConfiguration::PREFERENCE_DEFAULTS;
    }

    public function savePreferences(User $actor, array $data): array
    {
        DB::transaction(function () use ($actor, $data) {
            $this->actor($actor, false, true);
            NotificationPreference::insertOrIgnore(['user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $preference = NotificationPreference::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($preference->version !== (int) $data['version']) {
                throw new NotificationException('notifications.errors.conflict', 409);
            }
            foreach (['email', 'push'] as $channel) {
                if ($data[$channel.'_enabled'] && ! $preference->{$channel.'_enabled'} && ! $this->configuration->ready($channel)) {
                    throw new NotificationException('notifications.errors.notConfigured');
                }
            }
            $preference->fill(array_intersect_key($data, NotificationConfiguration::PREFERENCE_DEFAULTS));
            if ($preference->isDirty()) {
                $preference->version++;
                $preference->save();
                Log::notice('notification.preferences_changed', ['actor_uuid' => $actor->uuid, 'version' => $preference->version]);
            }
        });

        return $this->preferences($actor);
    }

    public function subscriptions(User $actor): array
    {
        $this->actor($actor);

        return PushSubscription::where('user_id', $actor->id)->orderByDesc('created_at')->get()->map(fn ($s) => ['id' => $s->id, 'createdAt' => $s->created_at->toISOString(), 'lastUsedAt' => $s->last_used_at?->toISOString(), 'currentKey' => hash_equals($s->vapid_key_hash, hash('sha256', config('alert_notifications.push.public_key'))), 'accountCurrent' => $s->user_version === $actor->session_version])->all();
    }

    public function subscribe(User $actor, array $data, string $sessionId = ''): array
    {
        if (! $this->endpoints->allowed($data['endpoint']) || ! $this->endpoints->validKeys($data['keys'])) {
            throw new NotificationException('notifications.errors.subscriptionInvalid');
        }
        if (! $this->configuration->ready('push') || ! $this->configuration->settings()['push_enabled']) {
            throw new NotificationException('notifications.errors.notConfigured');
        }
        try {
            $id = DB::transaction(function () use ($actor, $data, $sessionId) {
                $this->actor($actor, false, true);
                $hash = hash('sha256', $data['endpoint']);
                $subscription = PushSubscription::where('endpoint_hash', $hash)->lockForUpdate()->first();
                if ($subscription && $subscription->user_id !== $actor->id) {
                    throw new NotificationException('notifications.errors.subscriptionConflict', 409);
                }
                if (! $subscription && PushSubscription::where('user_id', $actor->id)->count() >= 10) {
                    throw new NotificationException('notifications.errors.subscriptionLimit');
                }
                $subscription ??= new PushSubscription(['user_id' => $actor->id, 'endpoint_hash' => $hash]);
                $subscription->fill(['endpoint' => $data['endpoint'], 'keys' => $data['keys'], 'user_version' => $actor->session_version, 'session_hash' => hash_hmac('sha256', $sessionId, config('app.key')), 'vapid_key_hash' => hash('sha256', config('alert_notifications.push.public_key'))]);
                if ($subscription->exists && $subscription->isDirty()) {
                    $subscription->version++;
                }
                $subscription->save();
                NotificationPreference::insertOrIgnore(['user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                $preference = NotificationPreference::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if (! $preference->push_enabled) {
                    $preference->push_enabled = true;
                    $preference->version++;
                    $preference->save();
                }
                Log::notice('notification.push_subscribed', ['actor_uuid' => $actor->uuid, 'subscription_uuid' => $subscription->id]);

                return $subscription->id;
            });
        } catch (UniqueConstraintViolationException) {
            throw new NotificationException('notifications.errors.subscriptionConflict', 409);
        }

        return ['id' => $id, 'preferences' => $this->preferences($actor)];
    }

    public function unsubscribe(User $actor, string $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $this->actor($actor, false, true);
            $subscription = PushSubscription::whereKey($id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $subscription->delete();
            Log::notice('notification.push_unsubscribed', ['actor_uuid' => $actor->uuid, 'subscription_uuid' => $id]);
        });
    }

    public function logoutDevice(User $actor, mixed $endpoint, ?string $sessionId = null): void
    {
        $validEndpoint = is_string($endpoint) && strlen($endpoint) <= 2048;
        if (! $validEndpoint && $sessionId === null) {
            return;
        }
        // Session binding also revokes a browser when client-side unsubscribe fails.
        if (! Schema::hasTable('push_subscriptions')) {
            return;
        }
        PushSubscription::where('user_id', $actor->id)->where(function ($q) use ($endpoint, $validEndpoint, $sessionId) {
            if ($sessionId !== null) {
                $q->where('session_hash', hash_hmac('sha256', $sessionId, config('app.key')));
            }
            if ($validEndpoint) {
                $q->orWhere('endpoint_hash', hash('sha256', $endpoint));
            }
        })->delete();
    }

    public function deliveries(User $actor, int $page, int $perPage = 20)
    {
        $current = $this->actor($actor);

        $query = NotificationDelivery::query();
        if (app(LocationAccess::class)->restricted($current)) {
            $query->whereIn('alert_episode_id', AlertEpisode::select('alert_episodes.id'));
        }

        return $query->where(function ($q) use ($actor, $current) {
            $q->where('user_id', $actor->id);
            if (Gate::forUser($current)->allows('notifications.manage')) {
                $q->orWhere('channel', 'webhook');
            }
        })->orderByDesc('created_at')->orderByDesc('id')->paginate(CollectionPagination::size(['per_page' => $perPage]), ['id', 'alert_episode_id', 'event', 'channel', 'status', 'attempts', 'error_code', 'created_at', 'sent_at', 'next_attempt_at'], 'page', $page);
    }
}
