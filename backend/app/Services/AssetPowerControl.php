<?php

namespace App\Services;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use App\Exceptions\AssetException;
use App\Jobs\RunScheduledDeviceCommand;
use App\Models\Asset;
use App\Models\DeviceCommand;
use App\Models\Sensor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AssetPowerControl
{
    private function authorize(User $actor, Asset $asset): void
    {
        Gate::forUser($actor)->authorize('update', $asset);
        if (! $asset->category->supportsPowerControl() || $asset->status !== AssetStatus::Active || ! $asset->power_control
            || ! DeviceControlClient::validTarget($asset->power_control['provider'] ?? null, $asset->power_control['entity'] ?? null)) {
            throw new AssetException('assets.power.unavailable', 422);
        }
        if (! app(DeviceControlClient::class)->matchesConnection($asset, $asset->power_control['provider'])) {
            throw new AssetException('assets.power.connectionMismatch', 422);
        }
    }

    public function result(DeviceCommand $command): array
    {
        return ['id' => $command->id, 'isOn' => $command->is_on, 'status' => $command->status, 'createdAt' => $command->created_at->toISOString(), 'confirmedAt' => $command->confirmed_at?->toISOString()];
    }

    public function context(User $actor, Asset $asset): array
    {
        $this->authorize($actor, $asset);
        $this->expirePending($asset->id);
        $last = DeviceCommand::where('asset_id', $asset->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        try {
            $state = app(DeviceControlClient::class)->state($asset->power_control);
        } catch (Throwable) {
            throw new AssetException('assets.power.unavailable', 502);
        }

        return ['isOn' => $state, 'lastCommand' => $last ? $this->result($last) : null];
    }

    private function expirePending(string $id): void
    {
        DeviceCommand::where('asset_id', $id)->where('status', 'pending')->where('created_at', '<=', now()->subMinutes(2))->update(['status' => 'uncertain']);
    }

    public function command(User $actor, Asset $target, bool $on, string $requestId, ?string $revision = null, ?string $scheduleKey = null, ?Sensor $channel = null): array
    {
        $lock = Cache::lock('asset-power:'.$target->id, 45);
        if (! $lock->get()) {
            throw new AssetException('assets.power.busy');
        }
        try {
            $reserve = function (bool $create, ?array $validated = null) use ($actor, $target, $on, $requestId, $revision, $scheduleKey, $channel) {
                return DB::transaction(function () use ($actor, $target, $on, $requestId, $revision, $scheduleKey, $channel, $create, $validated) {
                    $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                    if (! $current->is_active || $current->session_version !== $actor->session_version) {
                        throw new AuthenticationException;
                    }
                    $asset = Asset::whereKey($target->id)->lockForUpdate()->firstOrFail();
                    if ($channel) {
                        $channel = Sensor::whereKey($channel->id)->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail();
                        $control = app(SensorSwitchControl::class)->binding($current, $channel)['control'];
                    } else {
                        $this->authorize($current, $asset);
                        $control = $asset->power_control;
                    }
                    if ($revision !== null && ($asset->power_control['mode'] !== 'auto' || $asset->power_control['revision'] !== $revision || $asset->power_control['owner_uuid'] !== $current->uuid)) {
                        throw new AssetException('assets.power.changed');
                    }
                    $existing = $scheduleKey ? DeviceCommand::where('schedule_key', $scheduleKey)->first() : DeviceCommand::find($requestId);
                    if ($existing) {
                        if ($existing->asset_id !== $asset->id || $existing->actor_uuid !== $current->uuid || $existing->is_on !== $on || ($existing->control['sensor_id'] ?? null) !== $channel?->id) {
                            throw new AssetException('assets.power.conflict');
                        }
                        if ($existing->status !== 'confirmed') {
                            throw new AssetException('assets.power.uncertain');
                        }

                        return [$existing, false];
                    }
                    $this->expirePending($asset->id);
                    if (DeviceCommand::where('asset_id', $asset->id)->where('status', 'pending')->exists()) {
                        throw new AssetException('assets.power.busy');
                    }

                    if (! $create) {
                        return [null, true, $control];
                    }
                    if ($channel && $control !== $validated) {
                        throw new AssetException('assets.power.changed');
                    }

                    return [DeviceCommand::create(['id' => $requestId, 'asset_id' => $asset->id, 'actor_uuid' => $current->uuid, 'schedule_key' => $scheduleKey,
                        'is_on' => $on, 'status' => 'pending', 'control' => $control, 'created_at' => now()]), true];
                });
            };
            [$command, $fresh, $expected] = array_pad($reserve($channel === null), 3, null);
            if ($channel && $fresh) {
                $live = app(SensorSwitchControl::class)->binding($actor, Sensor::findOrFail($channel->id), true)['control'];
                if ($live !== $expected) {
                    throw new AssetException('assets.power.changed');
                }
                [$command, $fresh] = $reserve(true, $live);
            }
            if (! $fresh) {
                return $this->result($command);
            }
            try {
                app(DeviceControlClient::class)->setState($command->control, $on);
            } catch (Throwable) {
                $command->update(['status' => 'uncertain']);
                Log::warning('asset.power_uncertain', ['asset_uuid' => $target->id, 'command_uuid' => $command->id]);
                throw new AssetException('assets.power.uncertain', 502);
            }
            $command->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            Log::notice('asset.power_confirmed', ['asset_uuid' => $target->id, 'command_uuid' => $command->id, 'actor_uuid' => $actor->uuid, 'is_on' => $on]);

            return $this->result($command);
        } finally {
            $lock->release();
        }
    }

    public function enqueueDue(): void
    {
        $at = CarbonImmutable::now('UTC')->startOfMinute();
        Asset::withoutGlobalScopes()->where('status', 'active')->whereIn('category', AssetCategory::POWER_CONTROLLED)->whereNotNull('power_control')->where('power_control->mode', 'auto')
            ->select(['id', 'power_control'])->chunkById(100, function ($assets) use ($at) {
                foreach ($assets as $asset) {
                    $control = $asset->power_control;
                    $local = $at->setTimezone($control['timezone']);
                    foreach (['on_at' => true, 'off_at' => false] as $key => $on) {
                        if ($local->format('H:i') !== $control[$key]) {
                            continue;
                        }
                        // One wall-clock event per local day, including the repeated DST hour.
                        $slot = hash('sha256', $asset->id.':'.$control['revision'].':'.$local->format('Y-m-d').':'.$key);
                        RunScheduledDeviceCommand::dispatch($asset->id, $control['revision'], $on, $slot, $at->toISOString())->onQueue('controls');
                    }
                }
            });
    }

    public function scheduled(string $id, string $revision, bool $on, string $slot, string $plannedAt): void
    {
        // Expired queue work never replays an old physical command after an outage.
        if (abs(CarbonImmutable::parse($plannedAt)->diffInSeconds(CarbonImmutable::now())) > 90) {
            Log::notice('asset.power_schedule_expired', ['asset_uuid' => $id]);

            return;
        }
        $asset = Asset::withoutGlobalScopes()->find($id);
        if (! $asset || ($asset->power_control['revision'] ?? null) !== $revision) {
            return;
        }
        $actor = User::where('uuid', $asset->power_control['owner_uuid'])->first();
        if (! $actor) {
            return;
        }
        app(LocationAccess::class)->run($actor, fn () => $this->command($actor, $asset, $on, (string) Str::uuid(), $revision, $slot));
    }
}
