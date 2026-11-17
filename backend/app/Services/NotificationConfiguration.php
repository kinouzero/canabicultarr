<?php

namespace App\Services;

use App\Models\NotificationSetting;
use Illuminate\Support\Facades\Schema;

class NotificationConfiguration
{
    public const DEFAULTS = ['email_enabled' => false, 'push_enabled' => false, 'webhook_enabled' => false, 'include_recovery' => true, 'minimum_severity' => 'info', 'locale' => 'en', 'version' => 0];

    public const PREFERENCE_DEFAULTS = ['email_enabled' => false, 'push_enabled' => false, 'locale' => 'en', 'version' => 0];

    public function settings(): array
    {
        $record = Schema::hasTable('notification_settings') ? NotificationSetting::find(1) : null;

        return $record ? array_intersect_key($record->toArray(), self::DEFAULTS) : self::DEFAULTS;
    }

    public function ready(string $channel): bool
    {
        if ($channel === 'email') {
            return config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host')) && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
        }
        if ($channel === 'push') {
            $config = config('alert_notifications.push');
            $public = base64_decode(strtr($config['public_key'], '-_', '+/'), true);
            $private = base64_decode(strtr($config['private_key'], '-_', '+/'), true);
            $subject = $config['subject'];
            $validSubject = str_starts_with($subject, 'mailto:') ? filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false : (filter_var($subject, FILTER_VALIDATE_URL) !== false && parse_url($subject, PHP_URL_SCHEME) === 'https');

            return is_string($public) && strlen($public) === 65 && $public[0] === "\x04" && is_string($private) && strlen($private) === 32 && $validSubject;
        }
        if ($channel === 'webhook') {
            $config = config('alert_notifications.webhook');

            return app(WebhookDestination::class)->host($config['url']) !== null && in_array($config['format'], ['generic', 'ntfy', 'apprise'], true) && ($config['format'] !== 'ntfy' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $config['topic']));
        }

        return false;
    }

    public function fingerprint(string $channel): string
    {
        $value = $channel === 'email' ? [config('mail.default'), config('mail.mailers.smtp'), config('mail.from')] : config('alert_notifications.'.$channel);

        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
