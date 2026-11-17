<?php

namespace App\Jobs;

use App\Exceptions\AssetException;
use App\Services\AssetPowerControl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunScheduledDeviceCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public string $assetId, public string $revision, public bool $isOn, public string $slot, public string $plannedAt) {}

    public function handle(AssetPowerControl $control): void
    {
        try {
            $control->scheduled($this->assetId, $this->revision, $this->isOn, $this->slot, $this->plannedAt);
        } catch (AuthorizationException|AuthenticationException|AssetException $exception) {
            Log::notice('asset.power_schedule_failed', ['asset_uuid' => $this->assetId,
                'reason' => $exception instanceof AssetException ? $exception->errorCode : 'authorization']);
        }
    }
}
