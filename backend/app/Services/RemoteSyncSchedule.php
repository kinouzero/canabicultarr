<?php

namespace App\Services;

use App\Models\RemoteSensorLink;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class RemoteSyncSchedule
{
    public const MIN_INTERVAL = 60;

    public const MAX_INTERVAL = 86400;

    public function configuration(string $provider): array
    {
        $group = match ($provider) {
            'ha' => 'home_assistant', 'hb' => 'homebridge', default => throw new \LogicException('Unknown remote provider.')
        };
        $rawInterval = config($group.'.sync_interval_seconds', self::MIN_INTERVAL);
        $rawEnabled = config($group.'.sync_enabled', true);
        $interval = filter_var($rawInterval === '' || $rawInterval === null ? self::MIN_INTERVAL : $rawInterval, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN_INTERVAL, 'max_range' => self::MAX_INTERVAL]]);
        $enabled = filter_var($rawEnabled === '' || $rawEnabled === null ? true : $rawEnabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $valid = is_int($interval) && $interval % self::MIN_INTERVAL === 0 && is_bool($enabled);

        return ['enabled' => $valid && $enabled, 'configurationValid' => $valid, 'intervalSeconds' => $valid ? $interval : null];
    }

    public function eligibleQuery(RemoteSensorClient $client): Builder
    {
        $model = $client->linkModel();

        return $model::where('is_enabled', true)->whereHas('sensor', fn ($sensor) => $sensor->where('is_enabled', true)->whereHas('device', fn ($asset) => $asset->where('status', '!=', 'retired')));
    }

    public function due(RemoteSensorClient $client, RemoteSensorLink $link, ?CarbonImmutable $now = null): bool
    {
        $settings = $this->configuration($client->prefix());
        $now ??= CarbonImmutable::now('UTC');

        return $settings['enabled'] && $client->configured()
            && ($link->last_attempt_at === null || $link->last_attempt_at->lessThanOrEqualTo($now->subSeconds($settings['intervalSeconds'])));
    }

    public function nextEligibleAt(RemoteSensorClient $client, RemoteSensorLink $link, ?CarbonImmutable $now = null): ?string
    {
        $settings = $this->configuration($client->prefix());
        if (! $settings['enabled'] || ! $client->configured() || ! $link->is_enabled || ! $link->sensor->is_enabled || $link->sensor->device->status->value === 'retired') {
            return null;
        }
        $now ??= CarbonImmutable::now('UTC');
        $due = $link->last_attempt_at?->addSeconds($settings['intervalSeconds']) ?? $now;

        return ($due->lessThan($now) ? $now : $due)->ceilMinute()->toISOString();
    }

    public function status(RemoteSensorClient $client): array
    {
        $settings = $this->configuration($client->prefix());
        $row = $this->eligibleQuery($client)->selectRaw('COUNT(*) AS total, MAX(last_attempt_at) AS last_attempt, MAX(last_success_at) AS last_success, MIN(last_attempt_at) AS first_attempt, SUM(CASE WHEN last_attempt_at IS NULL THEN 1 ELSE 0 END) AS never_attempted')->first();
        $next = null;
        if ($settings['enabled'] && $client->configured() && $row->total > 0) {
            $now = CarbonImmutable::now('UTC');
            $due = $row->never_attempted > 0 ? $now : CarbonImmutable::parse($row->first_attempt)->addSeconds($settings['intervalSeconds']);
            $next = ($due->lessThan($now) ? $now : $due)->ceilMinute()->toISOString();
        }

        return [...$settings, 'eventsEnabled' => app(RemoteProviderEvents::class)->enabled($client->prefix()), 'linksCount' => (int) $row->total,
            'lastAttemptAt' => $row->last_attempt ? CarbonImmutable::parse($row->last_attempt)->toISOString() : null,
            'lastSuccessAt' => $row->last_success ? CarbonImmutable::parse($row->last_success)->toISOString() : null, 'nextEligibleAt' => $next];
    }
}
