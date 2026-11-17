<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\PermissionRole;
use App\Models\Site;
use App\Models\StorageLocation;
use App\Models\Strain;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\Inventory;
use App\Services\PlantTracking;
use App\Services\SensorAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionSortingTest extends TestCase
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

    public function test_sorting_applies_before_pagination_and_preserves_filters(): void
    {
        $this->actingAs($this->admin());
        for ($index = 0; $index < 25; $index++) {
            Asset::create(['name' => sprintf('Fictional sort %02d', $index), 'category' => 'fan']);
        }
        Asset::create(['name' => 'Fictional sort Z excluded', 'category' => 'light']);
        $first = $this->getJson('/api/v1/assets?search=Fictional&category=fan&sort=name_desc')->assertOk()->assertJsonPath('meta.total', 25)->assertJsonCount(20, 'data')->json('data');
        $second = $this->getJson('/api/v1/assets?search=Fictional&category=fan&sort=name_desc&page=2')->assertOk()->assertJsonCount(5, 'data')->json('data');
        $this->assertSame(array_map(fn ($index) => sprintf('Fictional sort %02d', $index), range(24, 0)), array_column([...$first, ...$second], 'name'));
        $this->getJson('/api/v1/assets?search=Fictional&category=fan')->assertOk()->assertJsonPath('data.0.name', 'Fictional sort 00');
        $this->getJson('/api/v1/assets?search=Fictional&category=fan&sort=name_desc&per_page=10&page=2')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 25)->assertJsonPath('data.0.name', 'Fictional sort 14');
        $this->getJson('/api/v1/assets?category=fan&per_page=100')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.last_page', 1);
    }

    public function test_page_sizes_are_bounded_on_search_endpoints(): void
    {
        $this->actingAs($this->admin());
        foreach (['plants', 'strains', 'assets', 'sensors', 'measurement-types', 'inventory/items', 'sites', 'users', 'permission-roles', 'comment-templates', 'events', 'documents', 'measurements/overview', 'integrations/sources', 'alerts', 'alert-rules', 'integrations/mqtt/sources', 'integrations/http/sources', 'integrations/home-assistant/entities', 'integrations/homebridge/entities'] as $endpoint) {
            foreach ([0, 101, 'invalid'] as $size) {
                $this->getJson('/api/v1/'.$endpoint.'?per_page='.$size)->assertUnprocessable()->assertJsonValidationErrors('per_page');
            }
        }
    }

    public function test_invalid_sorts_are_rejected_on_each_affected_endpoint(): void
    {
        $this->actingAs($this->admin());
        foreach (['plants', 'assets', 'sensors', 'measurement-types', 'inventory/items', 'sites', 'users', 'permission-roles', 'comment-templates', 'events', 'measurements/overview', 'integrations/sources', 'alerts', 'alert-rules'] as $endpoint) {
            $this->getJson('/api/v1/'.$endpoint.'?sort='.urlencode('name_desc; SELECT secret'))->assertUnprocessable()->assertJsonValidationErrors('sort');
        }
        $this->getJson('/api/v1/alert-rules?sort=name_desc')->assertOk();
        $this->getJson('/api/v1/alerts?sort=severity_desc')->assertOk();
    }

    public function test_plant_due_dates_sort_in_both_directions_with_undated_plants_last(): void
    {
        $admin = $this->admin();
        $site = Site::create(['name' => 'Fictional sorting site']);
        $strain = Strain::create(['name' => 'Fictional sorting strain']);
        foreach (['First' => now()->addDay(), 'Second' => now()->addDays(4), 'Undated' => null] as $name => $due) {
            $plant = app(PlantTracking::class)->create($admin, ['name' => $name, 'request_id' => (string) Str::uuid(), 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id]]);
            $plant->forceFill(['phase_due_at' => $due])->save();
        }
        $this->actingAs($admin)->getJson('/api/v1/plants?sort=due_asc')->assertOk()->assertJsonPath('data.0.name', 'First')->assertJsonPath('data.2.name', 'Undated');
        $this->getJson('/api/v1/plants?sort=due_desc')->assertOk()->assertJsonPath('data.0.name', 'Second')->assertJsonPath('data.2.name', 'Undated');
    }

    public function test_inventory_quantity_sort_uses_only_visible_stock_for_restricted_accounts(): void
    {
        $admin = $this->admin();
        $inside = StorageLocation::create(['name' => 'Fictional inside']);
        $outside = StorageLocation::create(['name' => 'Fictional outside']);
        $inventory = app(Inventory::class);
        $a = $inventory->save($admin, ['name' => 'Fictional A', 'unit' => 'pieces']);
        $b = $inventory->save($admin, ['name' => 'Fictional B', 'unit' => 'pieces']);
        $receive = fn ($item, $site, $quantity, $version) => $inventory->record($admin, $item, ['version' => $version, 'request_id' => (string) Str::uuid(), 'kind' => 'receipt', 'quantity' => $quantity, 'reason' => 'Fictional sorting receipt', 'destination' => ['id' => $site->id]]);
        $receive($a, $inside, '2', 1);
        $receive($a, $outside, '100', 2);
        $receive($b, $inside, '5', 1);
        $this->actingAs($admin)->getJson('/api/v1/inventory/items?sort=quantity_desc')->assertOk()->assertJsonPath('data.0.id', $a->id);
        $reader = User::factory()->create(['inventory_location_access' => [$inside->id]]);
        $this->actingAs($reader)->getJson('/api/v1/inventory/items?sort=quantity_desc')->assertOk()->assertJsonPath('data.0.id', $b->id)->assertJsonPath('data.1.totalQuantity', '2.000');
    }

    public function test_latest_reading_sort_excludes_hidden_and_future_samples_and_places_missing_last(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = $this->admin();
        $inside = Site::create(['name' => 'Fictional visible']);
        $outside = Site::create(['name' => 'Fictional hidden']);
        $type = MeasurementType::create(['name' => 'Fictional moisture', 'code' => 'fictional_sort', 'unit' => '%', 'nature' => 'substrate_moisture']);
        $sensors = [];
        foreach (['A older', 'B recent', 'C missing'] as $name) {
            $device = app(AssetAdministration::class)->save($admin, ['name' => $name.' device', 'locations' => [['type' => 'site', 'id' => $inside->id]]]);
            $sensors[] = app(SensorAdministration::class)->save($admin, ['name' => $name, 'device_id' => $device->id, 'measurement_type_id' => $type->id, 'channel_key' => 'moisture', 'is_enabled' => true]);
        }
        $sample = fn ($sensor, $at, $site) => Measurement::create(['sensor_id' => $sensor->id, 'value' => '42', 'unit' => '%', 'observed_at' => $at, 'received_at' => now(), 'site_id' => $site->id, 'location_snapshot' => ['type' => 'site', 'id' => $site->id, 'site' => ['id' => $site->id, 'name' => $site->name], 'room' => null, 'zone' => null]]);
        $sample($sensors[0], now()->subHours(3), $inside);
        $sample($sensors[1], now()->subHour(), $inside);
        $sample($sensors[0], now()->subMinute(), $outside);
        $sample($sensors[0], now()->addHour(), $inside);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $inside->id]]]);
        $this->actingAs($reader)->getJson('/api/v1/measurements/overview?sort=observed_desc')->assertOk()->assertJsonPath('data.0.sensor.id', $sensors[1]->id)->assertJsonPath('data.2.sensor.id', $sensors[2]->id);
        $this->getJson('/api/v1/measurements/overview?sort=observed_asc')->assertOk()->assertJsonPath('data.0.sensor.id', $sensors[0]->id)->assertJsonPath('data.2.sensor.id', $sensors[2]->id);
    }

    public function test_aggregate_counts_can_sort_profiles_and_measurement_types(): void
    {
        $admin = $this->admin();
        $one = PermissionRole::create(['name' => 'Fictional A', 'permissions' => []]);
        $two = PermissionRole::create(['name' => 'Fictional Z', 'permissions' => []]);
        User::factory()->create(['permission_role_ids' => [$two->id]]);
        $this->actingAs($admin)->getJson('/api/v1/permission-roles?search=Fictional&sort=users_desc')->assertOk()->assertJsonPath('data.0.id', $two->id)->assertJsonPath('data.1.id', $one->id);
        $this->getJson('/api/v1/measurement-types?sort=channels_desc')->assertOk();
    }
}
