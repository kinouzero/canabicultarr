<?php

namespace App\Services;

use App\Exceptions\MeasurementException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RemoteEventReceiver
{
    public function url(string $provider): string
    {
        $url = rtrim((string) config(app(RemoteProviderEvents::class)->group($provider).'.url'), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new MeasurementException('measurements.errors.notConfigured');
        }

        return $url;
    }

    public function receive(string $provider, int $seconds): void
    {
        $events = app(RemoteProviderEvents::class);
        app(ConnectionConfiguration::class)->apply();
        if (! $events->enabled($provider)) {
            return;
        }
        $retryKey = 'remote-events:'.$provider.':retry';
        $fingerprint = $events->fingerprint($provider);
        $retry = Cache::get($retryKey, []);
        if (($retry['fingerprint'] ?? null) !== $fingerprint) {
            $retry = [];
        }
        if (($retry['after'] ?? 0) > time()) {
            return;
        }
        $lock = Cache::lock('remote-events:'.$provider.':receiver', $seconds + 30);
        if (! $lock->get()) {
            return;
        }
        try {
            $fingerprint = $events->fingerprint($provider);
            $until = microtime(true) + $seconds;
            $nextCheck = 0;
            $count = 0;
            $continue = function () use ($provider, $events, $fingerprint, $until, &$nextCheck, &$count): bool {
                if (microtime(true) >= $until || $count >= 1000) {
                    return false;
                }
                if (microtime(true) >= $nextCheck) {
                    app(ConnectionConfiguration::class)->apply();
                    $nextCheck = microtime(true) + 2;
                }

                return $events->enabled($provider) && hash_equals($fingerprint, $events->fingerprint($provider));
            };
            $events->diagnostic($provider, 'connecting');
            app($provider === 'ha' ? HomeAssistantEventStream::class : HomebridgeEventStream::class)->receive(function (array $state) use ($events, $provider, $fingerprint, &$count) {
                $count++;
                if ($events->accept($provider, $state, $fingerprint)) {
                    $events->diagnostic($provider, 'receiving');
                }
            }, $continue);
            Cache::forget($retryKey);
            $events->diagnostic($provider, $count >= 1000 ? 'limited' : 'idle');
        } catch (Throwable) {
            $failures = min(5, ($retry['failures'] ?? 0) + 1);
            Cache::put($retryKey, ['fingerprint' => $fingerprint, 'failures' => $failures, 'after' => time() + min(300, 30 * 2 ** ($failures - 1))], 900);
            $events->diagnostic($provider, 'disconnected');
            Log::warning('measurement.events_disconnected', ['provider' => $provider]);
        } finally {
            $lock->release();
        }
    }
}
