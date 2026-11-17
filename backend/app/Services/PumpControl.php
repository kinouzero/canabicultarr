<?php

namespace App\Services;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use App\Exceptions\AssetException;
use App\Models\Asset;
use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\PumpActivation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class PumpControl
{
    public static function validTarget(?string $provider, mixed $entity): bool
    {
        return is_string($entity) && match ($provider) {
            'home_assistant' => preg_match('/^(switch|input_boolean|button|input_button|script)\.[a-z0-9_]+$/D', $entity) === 1,
            'homebridge' => preg_match('/^[a-f0-9]{64}:(On|Active)$/D', $entity) === 1,
            default => false,
        };
    }

    public function targets(string $provider, ?Asset $asset = null): array
    {
        return app(DeviceControlClient::class)->targets($provider, true, $asset);
    }

    private function client(string $provider): HomeAssistantClient|HomebridgeClient
    {
        return match ($provider) {
            'home_assistant' => app(HomeAssistantClient::class), 'homebridge' => app(HomebridgeClient::class), default => throw new AssetException('assets.pump.invalid', 422)
        };
    }

    private function authorize(User $actor, Asset $asset): void
    {
        Gate::forUser($actor)->authorize('update', $asset);
        if ($asset->category !== AssetCategory::Pump || $asset->status !== AssetStatus::Active || ! self::validTarget($asset->pump_control['provider'] ?? null, $asset->pump_control['entity'] ?? null)) {
            throw new AssetException('assets.pump.unavailable', 422);
        }
    }

    private function canStop(array $control): bool
    {
        return self::validTarget($control['provider'] ?? null, $control['entity'] ?? null)
            && ! preg_match('/^(button|input_button)\./', $control['entity']);
    }

    public function context(User $actor, Asset $asset, array $criteria = []): array
    {
        $status = $this->status($actor, $asset);
        $query = Plant::with(['strain', ...Plant::locationRelations()])->whereHas('devices', fn ($q) => $q->where('assets.id', $asset->id))->whereIn('phase', LifecycleDefinition::allowing('watering'));
        if (! empty($criteria['search'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($criteria['search'])).'%';
            $query->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern]);
        }
        if (! empty($criteria['plant_id'])) {
            $query->whereKey($criteria['plant_id']);
        }
        $page = $query->orderBy('name_key')->orderBy('id')->paginate(50, ['*'], 'page', $criteria['page'] ?? 1);
        $plants = $page->getCollection()->filter(fn ($plant) => Gate::forUser($actor)->allows('update', $plant));
        $latest = PumpActivation::select('id')->where('asset_id', $asset->id)->where('actor_uuid', $actor->uuid)->where('status', 'confirmed')->whereColumn('plant_id', 'plants.id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('plant_events')->whereColumn('plant_events.request_id', 'pump_activations.id')->whereColumn('plant_events.plant_id', 'pump_activations.plant_id')->where('plant_events.kind', 'watered'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(1);
        $ids = Plant::whereKey($plants->pluck('id'))->select('id')->selectSub($latest, 'activation_id')->get()->pluck('activation_id')->filter();
        $pending = PumpActivation::whereKey($ids)->get()->keyBy('plant_id');

        return [...$status, 'plantsMeta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => 50], 'plants' => $plants->map(fn ($plant) => ['id' => $plant->id, 'name' => $plant->displayName(), 'activation' => isset($pending[$plant->id]) ? $this->result($pending[$plant->id]) : null])->values()->all()];
    }

    public function status(User $actor, Asset $asset): array
    {
        $this->authorize($actor, $asset);
        $running = PumpActivation::where('asset_id', $asset->id)->whereNull('stopped_at')->where('control->mode', '!=', 'auto')->latest('created_at')->first();

        $stoppable = $running ?? PumpActivation::where('asset_id', $asset->id)->where('control->mode', 'auto')->latest('created_at')->orderByDesc('id')->first();
        if ($stoppable && ($stoppable->stopped_at || ! $this->canStop($stoppable->control))) {
            $stoppable = null;
        }

        return ['stoppable' => $stoppable ? Arr::except($this->result($stoppable), ['plantId', 'occurredAt']) : null, 'mode' => $asset->pump_control['mode'], 'seconds' => $asset->pump_control['seconds'] ?? null, 'running' => $running ? Arr::except($this->result($running), ['plantId', 'occurredAt']) : null];
    }

    private function result(PumpActivation $activation): array
    {
        return ['id' => $activation->id, 'plantId' => $activation->plant_id, 'occurredAt' => $activation->confirmed_at?->toISOString(), 'mode' => $activation->control['mode'], 'stopDueAt' => $activation->stop_due_at?->toISOString(), 'stoppedAt' => $activation->stopped_at?->toISOString(), 'stopStatus' => $activation->stop_status];
    }

    public function activate(User $actor, Asset $target, string $plantId, string $requestId): array
    {
        $lock = Cache::lock('pump-command:'.$target->id, 45);
        if (! $lock->get()) {
            throw new AssetException('assets.pump.busy');
        }
        try {
            [$activation, $fresh] = DB::transaction(function () use ($actor, $target, $plantId, $requestId) {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $asset = Asset::whereKey($target->id)->lockForUpdate()->firstOrFail();
                $this->authorize($actor, $asset);
                if (! app(DeviceControlClient::class)->matchesConnection($asset, $asset->pump_control['provider'])) {
                    throw new AssetException('assets.power.connectionMismatch', 422);
                }
                $plant = Plant::whereKey($plantId)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $plant);
                if (! $plant->devices()->where('assets.id', $asset->id)->exists() || ! in_array($plant->phase, LifecycleDefinition::allowing('watering'), true)) {
                    throw new AssetException('assets.pump.plantUnavailable', 422);
                }
                $existing = PumpActivation::find($requestId);
                if (! $existing && PlantEvent::where('plant_id', $plant->id)->where('request_id', $requestId)->exists()) {
                    throw new AssetException('assets.pump.conflict');
                }
                if ($existing) {
                    if ($existing->asset_id !== $asset->id || $existing->plant_id !== $plant->id || $existing->actor_uuid !== $actor->uuid) {
                        throw new AssetException('assets.pump.conflict');
                    }
                    if ($existing->status !== 'confirmed') {
                        throw new AssetException('assets.pump.uncertain');
                    }

                    return [$existing, false];
                }
                $context = $this->status($actor, $asset);
                if ($context['running']) {
                    throw new AssetException('assets.pump.running');
                }

                // Persist before network I/O: an ambiguous response must never trigger an automatic second command.
                return [PumpActivation::create(['id' => $requestId, 'asset_id' => $asset->id, 'plant_id' => $plant->id, 'actor_uuid' => $actor->uuid, 'status' => 'pending', 'control' => [...$asset->pump_control, 'name' => $asset->name], 'created_at' => now(), 'stop_due_at' => ($asset->pump_control['mode'] ?? 'auto') === 'timer' ? now()->addSeconds($asset->pump_control['seconds']) : null]), true];
            });
            if (! $fresh) {
                return $this->result($activation);
            }
            try {
                $this->command($activation->control, true);
            } catch (Throwable $exception) {
                $activation->update(['status' => 'uncertain']);
                Log::warning('pump.activation_uncertain', ['activation_uuid' => $activation->id, 'asset_uuid' => $activation->asset_id]);
                throw new AssetException('assets.pump.uncertain', 502);
            }
            $activation->update(['status' => 'confirmed', 'confirmed_at' => now(), 'stop_due_at' => $activation->control['mode'] === 'timer' ? now()->addSeconds($activation->control['seconds']) : null]);
            Log::notice('pump.activated', ['activation_uuid' => $activation->id, 'asset_uuid' => $activation->asset_id, 'actor_uuid' => $actor->uuid, 'plant_uuid' => $plantId]);

            return $this->result($activation);
        } finally {
            $lock->release();
        }
    }

    private function command(array $control, bool $on): void
    {
        $client = app(DeviceControlClient::class);
        if ($this->canStop($control) && (! $on || $control['mode'] !== 'auto')) {
            $client->setState($control, $on);

            return;
        }

        // A self-stopping pump may complete its cycle before a read can observe it running.
        $client->command($control, $on);
    }

    public function stop(PumpActivation $target, ?User $actor = null): array
    {
        // The scheduler must stop an already-started pump even if the initiating user's rights changed.
        if ($actor) {
            Gate::forUser($actor)->authorize('update', Asset::findOrFail($target->asset_id));
        }
        $lock = Cache::lock('pump-command:'.$target->asset_id, 45);
        if (! $lock->get()) {
            throw new AssetException('assets.pump.busy');
        }
        try {
            $activation = $target->fresh();
            if ($activation->stopped_at) {
                return $this->result($activation);
            }
            if (! $this->canStop($activation->control)) {
                throw new AssetException('assets.pump.notStoppable', 422);
            }
            try {
                $this->command($activation->control, false);
            } catch (Throwable) {
                $activation->update(['stop_status' => 'failed', 'stop_due_at' => $activation->control['mode'] === 'timer' ? now()->addSeconds(30) : null]);
                Log::warning('pump.stop_failed', ['activation_uuid' => $activation->id, 'asset_uuid' => $activation->asset_id]);
                throw new AssetException('assets.pump.stopFailed', 502);
            }
            $activation->update(['stopped_at' => now(), 'stop_status' => 'confirmed', 'stop_due_at' => null]);
            Log::notice('pump.stopped', ['activation_uuid' => $activation->id, 'asset_uuid' => $activation->asset_id]);

            return $this->result($activation);
        } finally {
            $lock->release();
        }
    }

    public function stopDue(): void
    {
        PumpActivation::whereNull('stopped_at')->where('stop_due_at', '<=', now())->orderBy('stop_due_at')->limit(20)->get()->each(function ($activation) {
            try {
                $this->stop($activation);
            } catch (AssetException) { /* Persistent due times allow the next scheduler tick to retry an idempotent stop. */
            }
        });
    }

    public function watering(User $actor, Plant $plant, string $requestId): ?array
    {
        $activation = PumpActivation::find($requestId);
        if (! $activation) {
            return null;
        }
        if ($activation->plant_id !== $plant->id || $activation->actor_uuid !== $actor->uuid || $activation->status !== 'confirmed') {
            throw new AssetException('assets.pump.conflict');
        }

        return ['activationId' => $activation->id, 'assetId' => $activation->asset_id, 'name' => $activation->control['name'], 'occurredAt' => $activation->confirmed_at->toISOString()];
    }
}
