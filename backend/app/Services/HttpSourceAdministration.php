<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\AuthFlowException;
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

class HttpSourceAdministration
{
    private function actor(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }
        Gate::forUser($current)->authorize('integrations.manage');

        return $current;
    }

    public function listing(User $actor, array $criteria)
    {
        Gate::forUser($actor->fresh())->authorize('integrations.manage');

        $query = HttpMeasurementSource::with([...SensorAdministration::relations('sensor.'), 'issuer.permissionRoles']);

        return IntegrationChannelFilters::apply($query, $criteria)->orderBy('created_at')->orderBy('id')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function apply(User $actor, array $data, ?HttpMeasurementSource $target = null, string $action = 'create'): array
    {
        try {
            [$source, $token] = DB::transaction(function () use ($actor, $data, $target, $action) {
                $current = $this->actor($actor);
                $sensorId = $target?->sensor_id ?? $data['sensor_id'];
                $initial = Sensor::find($sensorId);
                if (! $initial) {
                    throw new AuthFlowException('httpIngestion.errors.sensorUnavailable', 422);
                }
                $asset = Asset::whereKey($initial->asset_id)->lockForUpdate()->firstOrFail();
                app(LocationAccess::class)->assertRecord($current, $asset);
                $sensor = Sensor::whereKey($sensorId)->lockForUpdate()->firstOrFail();
                $source = $target ? HttpMeasurementSource::whereKey($target->id)->lockForUpdate()->firstOrFail() : new HttpMeasurementSource(['sensor_id' => $sensor->id]);
                if ($target && $source->version !== (int) $data['version']) {
                    throw new AuthFlowException('httpIngestion.errors.conflict', 409);
                }
                if ($action === 'remove') {
                    if (Measurement::where('http_measurement_source_id', $source->id)->exists()) {
                        throw new AuthFlowException('httpIngestion.errors.sourceHasMeasurements', 409);
                    }
                    $source->delete();

                    return [$source, null];
                }
                if (! $target && ((BluetoothLink::where('sensor_id', $sensor->id)->exists() || HomeAssistantLink::where('sensor_id', $sensor->id)->exists() || HomebridgeLink::where('sensor_id', $sensor->id)->exists() || MqttMeasurementSource::where('sensor_id', $sensor->id)->exists()) || HttpMeasurementSource::where('sensor_id', $sensor->id)->exists())) {
                    throw new AuthFlowException('httpIngestion.errors.alreadyLinked', 409);
                }
                if ((! $target || $action === 'rotate') && (! $sensor->is_enabled || $asset->status === AssetStatus::Retired)) {
                    throw new AuthFlowException('httpIngestion.errors.sensorUnavailable', 422);
                }
                $token = null;
                if ($action === 'create' || $action === 'rotate') {
                    $token = bin2hex(random_bytes(32));
                    $source->token_hash = hash('sha256', $token);
                    $source->issuer_id = $current->id;
                    $source->issuer_version = $current->session_version;
                    $source->expires_at = now()->addDays((int) $data['expires_days']);
                } elseif ($action === 'revoke') {
                    $source->token_hash = null;
                    $source->is_enabled = false;
                } else {
                    if ($data['is_enabled'] && ! $source->credentialCurrent()) {
                        throw new AuthFlowException('httpIngestion.errors.renewRequired', 422);
                    }
                    $source->is_enabled = (bool) $data['is_enabled'];
                }
                if ($target && $source->isDirty()) {
                    $source->version++;
                }
                $source->save();

                return [$source->load([...SensorAdministration::relations('sensor.'), 'issuer.permissionRoles']), $token];
            });
        } catch (UniqueConstraintViolationException) {
            throw new AuthFlowException('httpIngestion.errors.alreadyLinked', 409);
        }
        Log::notice('http_source.'.$action, ['actor_uuid' => $actor->uuid, 'source_uuid' => $source->id, 'sensor_uuid' => $source->sensor_id]);

        return [$source, $token];
    }
}
