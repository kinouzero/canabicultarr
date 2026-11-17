<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\AuthFlowException;
use App\Exceptions\MeasurementException;
use App\Models\Asset;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\HttpMeasurementSource;
use App\Models\Measurement;
use App\Models\MqttMeasurementSource;
use App\Models\Sensor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HttpMeasurementIngestion
{
    public function ingest(HttpMeasurementSource $hint, #[\SensitiveParameter] string $token, #[\SensitiveParameter] array $data): string
    {
        try {
            $outcome = DB::transaction(function () use ($hint, $token, $data) {
                $issuer = User::whereKey($hint->issuer_id)->lockForUpdate()->first();
                $initial = Sensor::findOrFail($hint->sensor_id);
                $asset = Asset::whereKey($initial->asset_id)->lockForUpdate()->firstOrFail();
                $sensor = Sensor::with('measurementType')->whereKey($hint->sensor_id)->lockForUpdate()->firstOrFail();
                $source = HttpMeasurementSource::whereKey($hint->id)->lockForUpdate()->firstOrFail();
                $source->setRelation('issuer', $issuer);
                if (! $issuer || $issuer->id !== $source->issuer_id || ! $source->is_enabled || ! $source->credentialCurrent() || ! hash_equals((string) $source->token_hash, hash('sha256', $token))) {
                    throw new AuthFlowException('httpIngestion.errors.invalidCredential', 401);
                }
                if (! $sensor->is_enabled || $asset->status === AssetStatus::Retired || (HomeAssistantLink::where('sensor_id', $sensor->id)->exists() || HomebridgeLink::where('sensor_id', $sensor->id)->exists() || MqttMeasurementSource::where('sensor_id', $sensor->id)->exists())) {
                    throw new AuthFlowException('httpIngestion.errors.sensorUnavailable', 409);
                }
                try {
                    $value = MeasurementValue::normalize($data['value']);
                    $quality = MeasurementQuality::normalize($data['quality'] ?? null, $value, $sensor->measurementType);
                } catch (MeasurementException) {
                    throw new AuthFlowException('httpIngestion.validation.valueInvalid', 422);
                }
                $time = CarbonImmutable::parse($data['observed_at'])->utc();
                if ($time->year < 2000 || $time->greaterThan(now()->addMinutes(5))) {
                    throw new AuthFlowException('httpIngestion.validation.timeInvalid', 422);
                }
                if ($data['unit'] !== $sensor->measurementType->unit) {
                    throw new AuthFlowException('httpIngestion.validation.unitInvalid', 422);
                }
                $observed = $time->format('Y-m-d H:i:s.u');
                $existing = Measurement::where('sensor_id', $sensor->id)->where('observed_at', $observed)->first();
                if ($existing && ($existing->quality !== $quality || $existing->value !== $value || $existing->unit !== $data['unit'] || $existing->http_measurement_source_id !== $source->id)) {
                    throw new AuthFlowException('httpIngestion.errors.sampleConflict', 409);
                }
                if (! $existing) {
                    Measurement::create(['sensor_id' => $sensor->id, 'home_assistant_link_id' => null, 'http_measurement_source_id' => $source->id, 'quality' => $quality, 'value' => $value, 'unit' => $data['unit'], 'observed_at' => $observed, 'received_at' => now(), ...app(MeasurementProvenance::class)->attributes($asset, $sensor, $observed, $source, 'http')]);
                }
                $source->last_received_at = now();
                $source->save();

                return $existing ? 'duplicate' : 'stored';
            });
        } catch (AuthFlowException $exception) {
            throw $exception;
        } catch (\Throwable) {
            Log::warning('http_ingestion.failed', ['source_uuid' => $hint->id]);
            throw new AuthFlowException('httpIngestion.errors.failed', 503);
        }
        Log::info('http_ingestion.accepted', ['source_uuid' => $hint->id, 'sensor_uuid' => $hint->sensor_id, 'outcome' => $outcome]);

        return $outcome;
    }
}
