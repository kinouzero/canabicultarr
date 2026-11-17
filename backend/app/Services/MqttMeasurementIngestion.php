<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\HttpMeasurementSource;
use App\Models\Measurement;
use App\Models\MqttMeasurementSource;
use App\Models\Sensor;
use App\Rules\ZonedTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MqttMeasurementIngestion
{
    public function __construct(private MqttConfiguration $configuration) {}

    public function ingest(string $id, int $version, #[\SensitiveParameter] string $topic, #[\SensitiveParameter] string $payload): string
    {
        try {
            return DB::transaction(function () use ($id, $version, $topic, $payload) {
                $hint = MqttMeasurementSource::find($id);
                if (! $hint || ! $this->configuration->ready()) {
                    return 'ignored';
                }
                $initial = Sensor::findOrFail($hint->sensor_id);
                $asset = Asset::whereKey($initial->asset_id)->lockForUpdate()->firstOrFail();
                $sensor = Sensor::with('measurementType')->whereKey($initial->id)->lockForUpdate()->firstOrFail();
                $source = MqttMeasurementSource::whereKey($id)->lockForUpdate()->firstOrFail();
                if (! $source->is_enabled || $source->version !== $version || $source->topic !== $topic || $source->instance_key !== $this->configuration->instanceKey() || ! $sensor->is_enabled || $asset->status === AssetStatus::Retired || HomeAssistantLink::where('sensor_id', $sensor->id)->exists() || HomebridgeLink::where('sensor_id', $sensor->id)->exists() || HttpMeasurementSource::where('sensor_id', $sensor->id)->exists()) {
                    return 'ignored';
                }
                $source->last_attempt_at = now();
                try {
                    if (strlen($payload) > 4096) {
                        throw new \InvalidArgumentException;
                    }
                    $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
                    if (! is_array($data) || array_is_list($data)) {
                        throw new \InvalidArgumentException;
                    }
                    $validator = Validator::make($data, ['quality' => ['sometimes', 'required', Rule::enum(\App\Enums\MeasurementQuality::class)], 'value' => ['required', 'string', 'max:80'], 'unit' => ['present', 'nullable', 'string', 'max:24'], 'observed_at' => ['required', new ZonedTimestamp]]);
                    if ($validator->fails()) {
                        throw new \InvalidArgumentException;
                    }
                    $value = MeasurementValue::normalize($data['value']);
                    $quality = MeasurementQuality::normalize($data['quality'] ?? null, $value, $sensor->measurementType);
                    $time = CarbonImmutable::parse($data['observed_at'])->utc();
                    if ($time->year < 2000 || $time->greaterThan(now()->addMinutes(5)) || $data['unit'] !== $sensor->measurementType->unit) {
                        throw new \InvalidArgumentException;
                    }
                } catch (\Throwable) {
                    $source->last_error = 'mqtt.errors.invalidSample';
                    $source->save();

                    return 'rejected';
                }
                $observed = $time->format('Y-m-d H:i:s.u');
                $existing = Measurement::where('sensor_id', $sensor->id)->where('observed_at', $observed)->first();
                if ($existing && ($existing->quality !== $quality || $existing->value !== $value || $existing->unit !== $data['unit'] || $existing->mqtt_measurement_source_id !== $source->id)) {
                    $source->last_error = 'mqtt.errors.sampleConflict';
                    $source->save();

                    return 'conflict';
                }
                if (! $existing) {
                    Measurement::create(['sensor_id' => $sensor->id, 'home_assistant_link_id' => null, 'http_measurement_source_id' => null, 'mqtt_measurement_source_id' => $source->id, 'quality' => $quality, 'value' => $value, 'unit' => $data['unit'], 'observed_at' => $observed, 'received_at' => now(), ...app(MeasurementProvenance::class)->attributes($asset, $sensor, $observed, $source, 'mqtt')]);
                }
                $source->last_error = null;
                $source->last_received_at = now();
                $source->save();

                return $existing ? 'duplicate' : 'stored';
            });
        } catch (\Throwable) {
            Log::warning('mqtt_ingestion.failed', ['source_uuid' => $id]);

            return 'failed';
        }
    }
}
