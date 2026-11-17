<?php

namespace Tests\Feature;

use App\Models\HomeAssistantLink;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\RemoteSensorCollector;
use App\Services\SensorAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConcurrentMeasurementTest extends TestCase
{
    use DatabaseMigrations;

    public function test_overlapping_collectors_store_one_immutable_sample(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent row locking requires PostgreSQL.');
        }
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Test Device']);
        $type = MeasurementType::create(['name' => 'Temperature', 'code' => 'temp', 'unit' => '°C']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => 'Probe', 'device_id' => $asset->id, 'measurement_type_id' => $type->id, 'channel_key' => 'probe']);
        $link = HomeAssistantLink::create(['instance_key' => hash('sha256', 'https://ha.example.test'), 'entity_id' => 'sensor.temperature', 'sensor_id' => $sensor->id]);
        $linkId = $link->id;
        $database = DB::connection()->getDatabaseName();
        $reported = now()->toISOString();
        $operations = [];
        for ($i = 0; $i < 2; $i++) {
            $operations[] = static function () use ($linkId, $database, $reported) {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database) {
                    return 'wrong_test_environment';
                }
                config(['home_assistant.url' => 'https://ha.example.test', 'home_assistant.token' => 'test-token']);
                Http::preventStrayRequests();
                Http::fake(['https://ha.example.test/api/states/sensor.temperature' => Http::response(['entity_id' => 'sensor.temperature', 'state' => '21.125', 'attributes' => ['unit_of_measurement' => '°C'], 'last_reported' => $reported])]);

                return app(RemoteSensorCollector::class)->collect(HomeAssistantLink::findOrFail($linkId))['outcome'];
            };
        }
        $results = Concurrency::driver('process')->run($operations, timeout: 20);
        sort($results);
        $this->assertSame(['duplicate', 'stored'], $results);
        $this->assertDatabaseCount('measurements', 1);
        $this->assertSame('21.125000000', Measurement::sole()->value);
        $this->assertSame('recorded', Measurement::sole()->provenance_snapshot['status']);
        Measurement::query()->delete();
    }
}
