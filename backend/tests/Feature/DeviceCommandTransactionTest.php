<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\HomebridgeLink;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\User;
use App\Services\AssetPowerControl;
use App\Services\HomebridgeClient;
use App\Services\LocationAccess;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceCommandTransactionTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // This isolated migration test must leave no history for guarded rollback.
        if (DB::getDriverName() === 'pgsql') {
            DB::table('device_commands')->delete();
        }
        parent::tearDown();
    }

    public function test_provider_preflight_holds_no_user_asset_or_sensor_row_lock(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL with independent row locks.');
        }
        config(['homebridge.url' => 'https://hb.example.test']);
        $this->configureHomebridge('https://hb.example.test', 'fictional-token');
        $actor = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $asset = Asset::create(['name' => 'Fictional lock probe', 'category' => 'fan']);
        $type = MeasurementType::create(['name' => 'Switch', 'code' => 'lock_probe', 'nature' => 'binary']);
        $sensor = new Sensor(['name' => 'Switch', 'channel_key' => 'switch']);
        $sensor->asset_id = $asset->id;
        $sensor->measurement_type_id = $type->id;
        $sensor->save();
        $service = str_repeat('a', 64);
        HomebridgeLink::create(['sensor_id' => $sensor->id, 'entity_id' => $service.':10', 'control_entity' => $service.':On', 'instance_key' => app(HomebridgeClient::class)->instanceKey()]);
        config(['database.connections.lock_probe' => config('database.connections.pgsql')]);
        $probed = false;
        $on = false;
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($actor, $asset, $sensor, $service, &$probed, &$on) {
            if ($request->method() === 'PUT') {
                $on = true;

                return Http::response([]);
            }
            $this->assertSame(0, DB::transactionLevel());
            $probe = DB::connection('lock_probe');
            try {
                $probe->transaction(function () use ($probe, $actor, $asset, $sensor) {
                    foreach (['users' => $actor->id, 'assets' => $asset->id, 'sensors' => $sensor->id] as $table => $id) {
                        $this->assertCount(1, $probe->select('SELECT id FROM '.$table.' WHERE id = ? FOR UPDATE NOWAIT', [$id]));
                    }
                });
                $probed = true;
                usleep(30000);
            } finally {
                DB::purge('lock_probe');
            }

            return Http::response(['uniqueId' => $service, 'serviceName' => 'Fictional switch', 'type' => 'Fan', 'serviceCharacteristics' => [['iid' => 10, 'type' => 'On', 'format' => 'bool', 'canRead' => true, 'canWrite' => true, 'value' => $on]]]);
        });
        $result = app(LocationAccess::class)->run($actor, fn () => app(AssetPowerControl::class)->command($actor, $asset, true, (string) Str::uuid(), channel: $sensor));
        $this->assertTrue($probed);
        $this->assertSame('confirmed', $result['status']);
        // Homebridge validates the target before its one PUT and reads it again afterward.
        Http::assertSentCount(4);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'PUT'));
    }
}
