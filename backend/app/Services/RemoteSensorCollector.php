<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\MeasurementException;
use App\Models\Asset;
use App\Models\Measurement;
use App\Models\RemoteSensorLink;
use App\Models\Sensor;
use App\Models\User;
use App\Rules\ZonedTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class RemoteSensorCollector
{
    public function __construct(private RemoteSensorClient $client) {}

    public function collect(RemoteSensorLink $target, ?User $actor = null, ?array $eventState = null, ?CarbonImmutable $receivedAt = null): array
    {
        if ($actor) {
            $current = $actor->fresh();
            if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
                throw new AuthenticationException;
            }
            Gate::forUser($current)->authorize('integrations.manage');
            if (! app(LocationAccess::class)->record($current, $target)) {
                throw new AuthorizationException;
            }
        }
        $expectedVersion = $target->version;
        $target = $this->client->linkModel()::with('sensor.device', 'sensor.measurementType')->find($target->id);
        if (! $target || $target->version !== $expectedVersion || ! $target->is_enabled || ! $target->sensor->is_enabled || $target->sensor->device->status === AssetStatus::Retired) {
            return ['outcome' => 'skipped', 'code' => 'measurements.errors.disabled'];
        }
        $sample = null;
        $error = null;
        $controlEntity = null;
        try {
            if ($target->instance_key !== $this->client->instanceKey()) {
                throw new MeasurementException('measurements.errors.instanceChanged');
            }
            $state = $this->client->normalizeState($eventState ?? $this->client->state($target->entity_id));
            $controlEntity = $this->client->controlEntity($state);
            $value = MeasurementValue::normalize($state['state'] ?? null);
            $unit = $state['attributes']['unit_of_measurement'] ?? null;
            if ($unit !== $target->sensor->measurementType->unit) {
                throw new MeasurementException('measurements.errors.unitMismatch');
            }
            $time = $state['last_reported'] ?? $state['last_updated'] ?? null;
            $invalid = false;
            (new ZonedTimestamp)->validate('time', $time, function () use (&$invalid) {
                $invalid = true;
            });
            if ($invalid) {
                throw new MeasurementException('measurements.errors.invalidTime');
            }
            $observed = CarbonImmutable::parse($time)->utc();
            if ($observed->year < 2000 || $observed->greaterThan(now()->addMinutes(5))) {
                throw new MeasurementException('measurements.errors.invalidTime');
            }
            $quality = MeasurementQuality::normalize($state['attributes']['canabicultarr_quality'] ?? null, $value, $target->sensor->measurementType);
            if (($state['attributes']['restored'] ?? false) === true && in_array($quality, \App\Enums\MeasurementQuality::USABLE, true)) {
                $quality = 'suspect';
            }
            $sample = ['quality' => $quality, 'value' => $value, 'unit' => $unit, 'observed_at' => $observed->format('Y-m-d H:i:s.u')];
        } catch (MeasurementException $exception) {
            $error = $exception->errorCode;
        }

        return DB::transaction(function () use ($target, $actor, $sample, $error, $eventState, $receivedAt, $controlEntity) {
            if ($actor) {
                $current = User::whereKey($actor->id)->lockForUpdate()->first();
                if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
                    throw new AuthenticationException;
                }
                Gate::forUser($current)->authorize('integrations.manage');
            }
            $asset = Asset::whereKey($target->sensor->asset_id)->lockForUpdate()->firstOrFail();
            if ($actor) {
                app(LocationAccess::class)->assertRecord($current, $asset);
            }
            $sensor = Sensor::with('measurementType')->whereKey($target->sensor_id)->lockForUpdate()->firstOrFail();
            $link = $this->client->linkModel()::whereKey($target->id)->lockForUpdate()->first();
            if (! $link || $link->version !== $target->version || ! $link->is_enabled || ! $sensor->is_enabled || $asset->status === AssetStatus::Retired) {
                return ['outcome' => 'skipped', 'code' => 'measurements.errors.disabled'];
            }
            $link->control_entity = $controlEntity;
            $outcome = 'failed';
            if ($eventState === null) {
                $link->last_attempt_at = now();
            }
            if ($sample !== null) {
                $existing = Measurement::where('sensor_id', $sensor->id)->where('observed_at', $sample['observed_at'])->first();
                if ($existing && ($existing->quality !== $sample['quality'] || $existing->value !== $sample['value'] || $existing->unit !== $sample['unit'])) {
                    $error = 'measurements.errors.sampleConflict';
                } else {
                    $outcome = $existing ? 'duplicate' : 'stored';
                    if (! $existing) {
                        Measurement::create([...$sample, 'sensor_id' => $sensor->id, $this->client->sourceColumn() => $link->id, ...app(MeasurementProvenance::class)->attributes($asset, $sensor, $sample['observed_at'], $link, ($this->client->sourceColumn() === 'homebridge_link_id' ? 'homebridge' : 'home_assistant')), 'received_at' => $receivedAt ?? now()]);
                    }
                    if ($eventState === null) {
                        $link->last_success_at = now();
                    }
                }
            }
            if ($eventState === null) {
                $link->last_error = $error;
                $link->save();
            }
            Log::info('measurement.collection', ['link_uuid' => $link->id, 'sensor_uuid' => $sensor->id, 'outcome' => $outcome, 'code' => $error]);

            return ['outcome' => $outcome, 'code' => $error];
        });
    }
}
