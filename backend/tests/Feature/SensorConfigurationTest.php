<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\MeasurementTypeAdministration;
use App\Services\SensorAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class SensorConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function device(array $fields = []): Asset
    {
        return Asset::create(['name' => 'Test Device', ...$fields]);
    }

    private function type(array $fields = []): MeasurementType
    {
        return MeasurementType::create(['name' => 'Temperature', 'code' => 'air_temperature', 'unit' => '°C', ...$fields]);
    }

    private function sensor(Asset $device, MeasurementType $type, array $fields = []): Sensor
    {
        $sensor = new Sensor(['name' => 'Test Sensor', 'channel_key' => 'air_temp', ...$fields]);
        $sensor->device()->associate($device);
        $sensor->measurementType()->associate($type);
        $sensor->save();

        return $sensor;
    }

    private function payload(Asset $device, MeasurementType $type): array
    {
        return ['name' => 'New Sensor', 'channel_key' => 'air_temp', 'device_id' => $device->id, 'measurement_type_id' => $type->id];
    }

    public function test_guests_and_members_have_correct_catalog_and_sensor_permissions(): void
    {
        $type = $this->type();
        $device = $this->device();
        $sensor = $this->sensor($device, $type);
        foreach (['/api/v1/measurement-types', '/api/v1/sensors'] as $url) {
            $this->getJson($url)->assertUnauthorized();
            $this->postJson($url, [])->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/measurement-types')->assertOk()->assertJsonPath('data.0.sensorsCount', 1);
        $this->getJson('/api/v1/sensors')->assertOk()->assertJsonPath('data.0.device.id', $device->id)
            ->assertJsonMissingPath('data.0.name_key')->assertJsonMissingPath('data.0.device.password');
        $this->postJson('/api/v1/measurement-types', ['name' => 'Denied', 'code' => 'denied'])->assertForbidden();
        $this->postJson('/api/v1/sensors', $this->payload($device, $type))->assertForbidden();
        $this->patchJson('/api/v1/sensors/'.$sensor->id, ['name' => 'Denied'])->assertForbidden();
        $this->patchJson('/api/v1/measurement-types/'.$type->id, ['name' => 'Denied'])->assertForbidden();
    }

    public function test_catalog_creation_normalizes_codes_and_generates_uuid(): void
    {
        $id = (string) Str::uuid();
        $this->actingAs($this->admin());
        $data = $this->postJson('/api/v1/measurement-types', ['id' => $id, 'name' => ' Air Temperature ', 'code' => ' AIR_TEMP ', 'unit' => '°C', 'decimal_places' => 0])
            ->assertCreated()->assertJsonPath('data.code', 'air_temp')->assertJsonPath('data.name', 'Air Temperature')
            ->assertJsonPath('data.decimalPlaces', 0)->assertJsonPath('data.sensorsCount', 0)->assertJsonMissingPath('data.name_key')->json('data');
        $this->assertTrue(Str::isUuid($data['id']));
        $this->assertNotSame($id, $data['id']);
        $this->postJson('/api/v1/measurement-types', ['name' => 'Other', 'code' => 'AIR_TEMP'])->assertConflict()->assertJsonPath('error.code', 'measurementTypes.errors.codeTaken');
        $this->postJson('/api/v1/measurement-types', ['name' => 'Ratio', 'code' => 'ratio', 'unit' => null])->assertCreated()->assertJsonPath('data.unit', null)->assertJsonPath('data.decimalPlaces', 2);
    }

    public function test_catalog_validation_rejects_invalid_codes_units_and_precision(): void
    {
        $this->actingAs($this->admin());
        foreach ([['name' => ' '], ['code' => 'bad-code'], ['code' => str_repeat('a', 51)], ['unit' => str_repeat('a', 25)],
            ['decimal_places' => -1], ['decimal_places' => 7], ['description' => str_repeat('x', 2001)]] as $bad) {
            $this->postJson('/api/v1/measurement-types', ['name' => 'Type', 'code' => 'valid', ...$bad])->assertUnprocessable();
        }
    }

    public function test_used_type_semantics_are_protected_while_labels_remain_editable(): void
    {
        $type = $this->type();
        $this->sensor($this->device(), $type);
        $this->actingAs($this->admin());
        foreach ([['code' => 'other'], ['unit' => 'K'], ['unit' => null], ['decimal_places' => 3]] as $change) {
            $this->patchJson('/api/v1/measurement-types/'.$type->id, $change)->assertConflict()->assertJsonPath('error.code', 'measurementTypes.errors.inUse');
        }
        $this->patchJson('/api/v1/measurement-types/'.$type->id, ['name' => 'Renamed', 'description' => 'Notes'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.unit', '°C')->assertJsonPath('data.sensorsCount', 1);
        $other = $this->type(['code' => 'unused']);
        $this->patchJson('/api/v1/measurement-types/'.$other->id, ['code' => 'updated', 'unit' => null, 'decimal_places' => 6])->assertOk()->assertJsonPath('data.unit', null);
    }

    public function test_sensor_creation_preserves_semantics_and_config_defaults(): void
    {
        $type = $this->type();
        $device = $this->device(['category' => 'pump']);
        $forged = (string) Str::uuid();
        $this->actingAs($this->admin());
        $data = $this->postJson('/api/v1/sensors', [...$this->payload($device, $type), 'id' => $forged, 'channel_key' => ' AIR_TEMP ', 'asset_id' => $forged])
            ->assertCreated()->assertJsonPath('data.device.category', 'pump')->assertJsonPath('data.channelKey', 'air_temp')->assertJsonPath('data.isEnabled', true)
            ->assertJsonPath('data.expectedIntervalSeconds', null)->assertJsonPath('data.measurementType.unit', '°C')->json('data');
        $this->assertTrue(Str::isUuid($data['id']));
        $this->assertNotSame($forged, $data['id']);
        $this->assertDatabaseHas('sensors', ['id' => $data['id'], 'asset_id' => $device->id, 'measurement_type_id' => $type->id]);
    }

    public function test_only_nonretired_devices_can_receive_new_sensor_definitions(): void
    {
        $type = $this->type();
        $this->actingAs($this->admin());
        $equipment = $this->device(['category' => 'fan']);
        $this->postJson('/api/v1/sensors', $this->payload($equipment, $type))->assertCreated()->assertJsonPath('data.device.id', $equipment->id);
        $retired = $this->device(['status' => 'retired']);
        $this->postJson('/api/v1/sensors', $this->payload($retired, $type))->assertUnprocessable()->assertJsonPath('error.code', 'sensors.errors.deviceRetired');
        $maintenance = $this->device(['status' => 'maintenance']);
        $this->postJson('/api/v1/sensors', $this->payload($maintenance, $type))->assertCreated();
        $this->postJson('/api/v1/sensors', [...$this->payload($maintenance, $type), 'device_id' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/api/v1/sensors', [...$this->payload($maintenance, $type), 'measurement_type_id' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_channels_are_unique_per_device_not_globally(): void
    {
        $type = $this->type();
        $one = $this->device();
        $two = $this->device(['name' => 'Second Device']);
        $this->actingAs($this->admin());
        $this->postJson('/api/v1/sensors', $this->payload($one, $type))->assertCreated();
        $this->postJson('/api/v1/sensors', [...$this->payload($one, $type), 'channel_key' => 'AIR_TEMP'])->assertConflict()->assertJsonPath('error.code', 'sensors.errors.channelTaken');
        $this->postJson('/api/v1/sensors', $this->payload($two, $type))->assertCreated();
    }

    public function test_invalid_channel_configuration_and_mutable_fields_are_rejected(): void
    {
        $type = $this->type();
        $device = $this->device();
        $this->actingAs($this->admin());
        foreach ([['name' => ' '], ['channel_key' => 'bad-key'], ['channel_key' => str_repeat('x', 65)], ['is_enabled' => 'bad'],
            ['expected_interval_seconds' => 0], ['expected_interval_seconds' => 86401], ['expected_interval_seconds' => 1.5]] as $bad) {
            $this->postJson('/api/v1/sensors', [...$this->payload($device, $type), ...$bad])->assertUnprocessable();
        }
        $sensor = $this->sensor($device, $type);
        $other = $this->type(['code' => 'other']);
        foreach ([['device_id' => $this->device()->id], ['measurement_type_id' => $other->id], ['channel_key' => 'different']] as $change) {
            $this->patchJson('/api/v1/sensors/'.$sensor->id, $change)->assertUnprocessable();
        }
        $this->patchJson('/api/v1/sensors/'.$sensor->id, ['name' => 'Renamed', 'is_enabled' => false, 'expected_interval_seconds' => 86400])
            ->assertOk()->assertJsonPath('data.isEnabled', false)->assertJsonPath('data.expectedIntervalSeconds', 86400);
        $this->patchJson('/api/v1/sensors/'.$sensor->id, ['is_enabled' => true, 'expected_interval_seconds' => null])
            ->assertOk()->assertJsonPath('data.isEnabled', true)->assertJsonPath('data.expectedIntervalSeconds', null);
    }

    public function test_sensor_location_follows_the_device_without_separate_assignments(): void
    {
        $type = $this->type();
        $device = $this->device();
        $sensor = $this->sensor($device, $type);
        $admin = $this->admin();
        $site = Site::create(['name' => 'Device Location']);
        $this->actingAs($admin)->getJson('/api/v1/sensors')->assertJsonPath('data.0.device.location', null);
        app(AssetAdministration::class)->save($admin, ['locations' => [['type' => 'site', 'id' => $site->id]]], $device);
        $this->getJson('/api/v1/sensors')->assertJsonPath('data.0.device.locations.0.site.id', $site->id);
        $this->assertDatabaseMissing('sensors', ['id' => $sensor->id, 'name' => 'Device Location']);
    }

    public function test_search_filters_and_pagination_are_scoped_and_literal(): void
    {
        $type = $this->type();
        $device = $this->device();
        for ($i = 0; $i < 22; $i++) {
            $this->sensor($device, $type, ['name' => 'Sensor '.$i, 'channel_key' => 'channel_'.$i]);
        }
        $other = $this->device(['name' => 'Unique Device']);
        $match = $this->sensor($other, $type, ['name' => 'Literal _% Name', 'is_enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/sensors')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23);
        $this->getJson('/api/v1/sensors?page=2')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/sensors?search='.urlencode('_%'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
        $this->getJson('/api/v1/sensors?device_id='.$other->id.'&is_enabled=0')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sensors?search=unique')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sensors?measurement_type_id='.$type->id.'&is_enabled=1')->assertJsonPath('meta.total', 22);
        $this->getJson('/api/v1/sensors?search=AIR_TEMPERATURE')->assertJsonPath('meta.total', 23);
        $this->getJson('/api/v1/sensors?page=-1')->assertUnprocessable();
        $this->getJson('/api/v1/sensors?device_id=bad')->assertUnprocessable();
        for ($i = 0; $i < 20; $i++) {
            $this->type(['code' => 'type_'.$i]);
        }
        $this->getJson('/api/v1/measurement-types')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21);
        $this->getJson('/api/v1/measurement-types?search=AIR_TEMPERATURE')->assertJsonCount(1, 'data');
    }

    public function test_stale_actors_and_revoked_sessions_cannot_write_or_read(): void
    {
        $admin = $this->admin();
        $stale = clone $admin;
        $admin->permission_role_ids = [AccessProfileSeeder::defaultProfile()->id];
        $admin->save();
        try {
            app(MeasurementTypeAdministration::class)->save($stale, ['name' => 'Denied', 'code' => 'denied']);
            $this->fail('Stale actor must be rejected.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('measurement_types', 0);
        }
        $admin->forceFill(['session_version' => 1])->save();
        $this->actingAs($admin)->withSession(['auth_version' => 0])->getJson('/api/v1/sensors')->assertUnauthorized();
        $admin->forceFill(['is_active' => false])->save();
        $this->actingAs($admin)->getJson('/api/v1/measurement-types')->assertUnauthorized();
    }

    public function test_foreign_keys_preserve_device_and_type_references(): void
    {
        $type = $this->type();
        $device = $this->device();
        $this->sensor($device, $type);
        foreach ([$device, $type] as $parent) {
            try {
                DB::transaction(fn () => $parent->delete());
                $this->fail('Referenced parent must remain.');
            } catch (QueryException) {
                $this->assertTrue($parent->newQuery()->whereKey($parent->id)->exists());
            }
        }
    }

    public function test_audit_records_only_identifiers_and_field_names(): void
    {
        $type = $this->type();
        $device = $this->device();
        $admin = $this->admin();
        Log::spy();
        $sensor = app(SensorAdministration::class)->save($admin, $this->payload($device, $type));
        Log::shouldHaveReceived('notice')->with('sensor.created', ['actor_uuid' => $admin->uuid, 'sensor_uuid' => $sensor->id,
            'fields' => ['asset_id', 'measurement_type_id', 'channel_key', 'name']])->once();
    }

    public function test_measurement_nature_distinguishes_air_and_substrate_and_requires_valid_semantics(): void
    {
        $this->actingAs($this->admin());
        $input = ['name' => 'Substrate moisture', 'code' => 'substrate_moisture', 'unit' => '%', 'nature' => 'substrate_moisture'];
        $id = $this->postJson('/api/v1/measurement-types', $input)->assertCreated()->assertJsonPath('data.nature', 'substrate_moisture')->assertJsonMissingPath('data.isPlantMoisture')->json('data.id');
        $this->sensor($this->device(), MeasurementType::findOrFail($id));
        $this->patchJson('/api/v1/measurement-types/'.$id, ['nature' => 'air_humidity'])->assertOk()->assertJsonPath('data.nature', 'air_humidity');
        $this->patchJson('/api/v1/measurement-types/'.$id, ['nature' => 'substrate_moisture'])->assertOk();
        $this->postJson('/api/v1/measurement-types', [...$input, 'code' => 'invalid_soil', 'unit' => 'ratio'])->assertUnprocessable();
        $this->patchJson('/api/v1/measurement-types/'.$id, ['nature' => 'unknown'])->assertUnprocessable()->assertJsonPath('errors.nature.0', 'measurementTypes.validation.natureInvalid');
        $this->postJson('/api/v1/measurement-types', ['name' => 'Ambient', 'code' => 'ambient', 'unit' => '%', 'nature' => 'air_humidity'])->assertCreated()->assertJsonPath('data.nature', 'air_humidity');
        $this->postJson('/api/v1/measurement-types', ['name' => 'Custom', 'code' => 'custom'])->assertCreated()->assertJsonPath('data.nature', 'other');
    }

    public function test_nature_migration_preserves_substrate_and_channel_identity_without_guessing_manual_labels(): void
    {
        $soil = MeasurementType::create(['name' => 'Soil', 'code' => 'custom_soil', 'unit' => '%', 'nature' => 'substrate_moisture']);
        $sensor = $this->sensor($this->device(), $soil);
        $code = 'ha_'.substr(hash('sha256', json_encode(['humidity', '%'])), 0, 32);
        $air = MeasurementType::create(['name' => 'Humidity', 'code' => $code, 'unit' => '%']);
        $unmarkedCode = 'ha_'.substr(hash('sha256', json_encode(['moisture', '%'])), 0, 32);
        $unmarked = MeasurementType::create(['name' => 'Unmarked provider type', 'code' => $unmarkedCode, 'unit' => '%']);
        $manual = MeasurementType::create(['name' => 'Soil moisture', 'code' => 'manual_unknown', 'unit' => '%']);
        $migration = require database_path('migrations/2026_11_09_210000_replace_moisture_flag_with_measurement_nature.php');
        $migration->down();
        $this->assertDatabaseHas('measurement_types', ['id' => $soil->id, 'is_plant_moisture' => true]);
        $migration->up();
        $this->assertSame('substrate_moisture', $soil->fresh()->nature);
        $this->assertSame('air_humidity', $air->fresh()->nature);
        $this->assertSame('other', $manual->fresh()->nature);
        $this->assertSame('other', $unmarked->fresh()->nature);
        $this->assertSame($soil->id, $sensor->fresh()->measurement_type_id);
    }
}
