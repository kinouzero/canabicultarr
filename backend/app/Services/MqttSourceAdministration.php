<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\AuthFlowException;
use App\Models\ApplicationSetup;
use App\Models\Asset;
use App\Models\BluetoothLink;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\HttpMeasurementSource;
use App\Models\Measurement;
use App\Models\MqttMeasurementSource;
use App\Models\Sensor;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class MqttSourceAdministration
{
    public function __construct(private MqttConfiguration $configuration) {}

    public function listing(User $actor, array $criteria)
    {
        Gate::forUser($actor->fresh())->authorize('integrations.manage');

        $query = MqttMeasurementSource::with(SensorAdministration::relations('sensor.'));

        return IntegrationChannelFilters::apply($query, $criteria, 'topic')->orderBy('created_at')->orderBy('id')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function save(User $actor, array $data, ?MqttMeasurementSource $target = null, bool $remove = false): MqttMeasurementSource
    {
        try {
            $source = DB::transaction(function () use ($actor, $data, $target, $remove) {
                if (! $target) {
                    ApplicationSetup::whereKey(1)->lockForUpdate()->firstOrFail();
                    if (MqttMeasurementSource::where('instance_key', $this->configuration->instanceKey())->count() >= 100) {
                        throw new AuthFlowException('mqtt.errors.capacity', 422);
                    }
                }
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if (! $current->is_active || $current->session_version !== $actor->session_version) {
                    throw new AuthenticationException;
                }
                Gate::forUser($current)->authorize('integrations.manage');
                if (! $remove && ! $this->configuration->ready()) {
                    throw new AuthFlowException('mqtt.errors.notConfigured', 422);
                }
                $initial = Sensor::findOrFail($target?->sensor_id ?? $data['sensor_id']);
                $asset = Asset::whereKey($initial->asset_id)->lockForUpdate()->firstOrFail();
                app(LocationAccess::class)->assertRecord($current, $asset);
                $sensor = Sensor::whereKey($initial->id)->lockForUpdate()->firstOrFail();
                $source = $target ? MqttMeasurementSource::whereKey($target->id)->lockForUpdate()->firstOrFail() : new MqttMeasurementSource(['sensor_id' => $sensor->id, 'topic' => $data['topic'], 'instance_key' => $this->configuration->instanceKey()]);
                if ($target && $source->version !== (int) $data['version']) {
                    throw new AuthFlowException('mqtt.errors.conflict', 409);
                }
                if ($remove) {
                    if (Measurement::where('mqtt_measurement_source_id', $source->id)->exists()) {
                        throw new AuthFlowException('mqtt.errors.sourceHasMeasurements', 409);
                    }
                    $source->delete();

                    return $source;
                }
                if (! $target && (BluetoothLink::where('sensor_id', $sensor->id)->exists() || HomeAssistantLink::where('sensor_id', $sensor->id)->exists() || HomebridgeLink::where('sensor_id', $sensor->id)->exists() || HttpMeasurementSource::where('sensor_id', $sensor->id)->exists() || MqttMeasurementSource::where('sensor_id', $sensor->id)->exists())) {
                    throw new AuthFlowException('mqtt.errors.alreadyLinked', 409);
                }
                $enable = $target ? (bool) $data['is_enabled'] : true;
                if ($enable && (! $sensor->is_enabled || $asset->status === AssetStatus::Retired)) {
                    throw new AuthFlowException('mqtt.errors.sensorUnavailable', 422);
                }
                if ($enable && $source->instance_key !== $this->configuration->instanceKey()) {
                    throw new AuthFlowException('mqtt.errors.instanceChanged', 422);
                }
                $source->is_enabled = $enable;
                if ($target && $source->isDirty()) {
                    $source->version++;
                }
                $source->save();

                return $source->load(SensorAdministration::relations('sensor.'));
            });
        } catch (UniqueConstraintViolationException) {
            throw new AuthFlowException('mqtt.errors.alreadyLinked', 409);
        }
        Log::notice($remove ? 'mqtt_source.removed' : 'mqtt_source.saved', ['actor_uuid' => $actor->uuid, 'source_uuid' => $source->id]);

        return $source;
    }
}
