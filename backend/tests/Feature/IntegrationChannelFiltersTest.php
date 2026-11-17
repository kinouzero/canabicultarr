<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\BluetoothDevice;
use App\Models\BluetoothLink;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\HttpMeasurementSource;
use App\Models\MeasurementType;
use App\Models\MqttMeasurementSource;
use App\Models\Sensor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntegrationChannelFiltersTest extends TestCase
{
    use RefreshDatabase;

    public static function sources(): array
    {
        return [
            'Home Assistant' => ['home-assistant/links', HomeAssistantLink::class, 'entity_id'],
            'Homebridge' => ['homebridge/links', HomebridgeLink::class, 'entity_id'],
            'MQTT' => ['mqtt/sources', MqttMeasurementSource::class, 'topic'],
            'HTTP' => ['http/sources', HttpMeasurementSource::class, null],
            'Bluetooth' => ['bluetooth/devices', BluetoothLink::class, 'entity_id'],
        ];
    }

    #[DataProvider('sources')]
    public function test_search_and_state_filter_the_whole_integration_list(string $endpoint, string $model, ?string $reference): void
    {
        $actor = User::factory()->create(['permission_grants' => ['integrations.manage']]);
        $path = '/api/v1/integrations/'.$endpoint;
        $type = MeasurementType::create(['name' => 'Fictional temperature', 'code' => 'demo_temperature', 'unit' => '°C']);
        $matches = [];
        foreach (range(1, 12) as $position) {
            $asset = Asset::create(['name' => $position === 12 ? 'South greenhouse' : "North box $position", 'status' => 'active']);
            $sensor = new Sensor(['name' => $position === 12 ? 'Probe_12' : "ProbeX$position", 'channel_key' => "probe-$position"]);
            $sensor->asset_id = $asset->id;
            $sensor->measurement_type_id = $type->id;
            $sensor->save();

            $fields = ['sensor_id' => $sensor->id, 'is_enabled' => $position !== 12];
            if ($reference !== null) {
                $fields += [$reference => sprintf('provider.relay.%02d', $position), 'instance_key' => 'fictional-instance'];
            } else {
                $fields += ['issuer_id' => $actor->id, 'issuer_version' => $actor->session_version, 'expires_at' => now()->addDay()];
            }
            if ($model === BluetoothLink::class) {
                $device = BluetoothDevice::create(['asset_id' => $asset->id, 'device_key' => hash('sha256', (string) $position), 'address' => sprintf('00:11:22:33:44:%02d', $position)]);
                $fields['bluetooth_device_id'] = $device->id;
            }
            $source = $model::create($fields);
            $matches[] = $model === BluetoothLink::class ? $device->id : $source->id;
        }

        $this->actingAs($actor)->getJson($path.'?per_page=10')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12);
        foreach (['PROBE_', 'South greenhouse', 'probe-12', ...($reference === null ? [] : ['PROVIDER.RELAY.12'])] as $term) {
            $this->getJson($path.'?'.http_build_query(['search' => $term, 'state' => 'disabled', 'per_page' => 10]))
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $matches[11]);
        }
        $this->getJson($path.'?search=PROBE_')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($path.'?search=South&state=enabled')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($path.'?state=enabled')->assertOk()->assertJsonPath('meta.total', 11);
    }
}
