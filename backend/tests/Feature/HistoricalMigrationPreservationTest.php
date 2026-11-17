<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\HomeAssistantLink;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\OidcIdentity;
use App\Models\Site;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\Inventory;
use App\Services\SensorAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HistoricalMigrationPreservationTest extends TestCase
{
    // SQLite schema rebuilds must run outside a surrounding test transaction so
    // Laravel can suspend and restore FK enforcement without cascading identities.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function hierarchy(): array
    {
        $site = Site::create(['name' => 'Preserved Site']);
        $room = $site->rooms()->create(['name' => 'Preserved Room']);
        $zone = $room->zones()->create(['name' => 'Preserved Zone']);

        return [$site, $room, $zone];
    }

    /** Model the preexisting singleton history exercised by historical migration tests. */
    private function historicalAsset(User $actor, string $name, array $location): Asset
    {
        $asset = $this->placeAsset(Asset::create(['name' => $name]), $location);
        $asset->assignments()->create([...$location, 'user_id' => $actor->id, 'assigned_at' => now()]);

        return $asset;
    }

    private function graph(): array
    {
        $admin = $this->admin();
        [$site, $room, $zone] = $this->hierarchy();
        $asset = $this->historicalAsset($admin, 'Original Device', ['zone_id' => $zone->id]);
        $type = MeasurementType::create(['name' => 'Type', 'code' => 'type']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => 'Probe', 'channel_key' => 'probe', 'device_id' => $asset->id, 'measurement_type_id' => $type->id]);

        return [$admin, $site, $room, $zone, $asset, $sensor];
    }

    public function test_migration_reapply_preserves_accounts_oidc_and_locations(): void
    {
        $admin = $this->admin();
        $hash = $admin->password;
        [$site, $room, $zone] = $this->hierarchy();
        OidcIdentity::create(['user_id' => $admin->id, 'issuer' => 'https://example.test/', 'subject' => 'subject']);
        $this->reapplySchemaFrom('2026_11_06_060000_create_assets_and_assignments.php');
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertDatabaseHas('zones', ['id' => $zone->id, 'room_id' => $room->id]);
        $this->assertDatabaseCount('oidc_identities', 1);
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_migration_reapplication_preserves_existing_infrastructure_and_identity(): void
    {
        [$admin, $site, $room, $zone, $asset, $sensor] = $this->graph();
        $hash = $admin->password;
        OidcIdentity::create(['user_id' => $admin->id, 'issuer' => 'https://example.test/', 'subject' => 'subject']);
        $this->reapplySchemaFrom('2026_11_06_080000_create_event_journal.php');
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertDatabaseCount('oidc_identities', 1);
        $this->assertDatabaseHas('sensors', ['id' => $sensor->id, 'asset_id' => $asset->id]);
        $this->assertDatabaseCount('asset_assignments', 1);
        $this->assertDatabaseCount('event_entries', 0);
    }

    public function test_migration_rollback_and_reapply_preserve_accounts_and_identity_links(): void
    {
        $admin = $this->admin();
        $password = $admin->password;
        OidcIdentity::create(['user_id' => $admin->id, 'issuer' => 'https://example.test/', 'subject' => 'test-subject']);
        $this->reapplySchemaFrom('2026_11_06_050000_create_location_hierarchy.php');
        $this->assertSame($password, $admin->fresh()->password);
        $this->assertTrue($admin->fresh()->isAdministrator());
        $this->assertDatabaseCount('oidc_identities', 1);
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_migration_reapply_preserves_accounts_assets_and_location_history(): void
    {
        $admin = $this->admin();
        $hash = $admin->password;
        $site = Site::create(['name' => 'Preserved Site']);
        OidcIdentity::create(['user_id' => $admin->id, 'issuer' => 'https://example.test/', 'subject' => 'subject']);
        $device = $this->historicalAsset($admin, 'Preserved Device', ['site_id' => $site->id]);
        $this->reapplySchemaFrom('2026_11_06_070000_create_measurement_types_and_sensors.php');
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertDatabaseCount('oidc_identities', 1);
        $this->assertDatabaseHas('asset_locations', ['asset_id' => $device->id, 'site_id' => $site->id]);
        $this->assertDatabaseCount('asset_assignments', 1);
        $this->assertDatabaseCount('sensors', 0);
    }

    public function test_additive_schema_preserves_manual_records_and_inventory(): void
    {
        $admin = $this->admin();
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Manual']);
        $item = app(Inventory::class)->save($admin, ['name' => 'Supply', 'unit' => 'kg']);
        $this->reapplySchemaFrom('2026_11_06_110000_create_measurements.php');
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id]);
        $this->assertDatabaseHas('users', ['uuid' => $admin->uuid]);
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_additive_migration_preserves_source_links_measurements_and_manual_assets(): void
    {
        $admin = $this->admin();
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Test Device']);
        $type = MeasurementType::create(['name' => 'Test Type', 'code' => 'preserved_alert_type', 'unit' => 'test']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => 'Test Probe', 'device_id' => $asset->id, 'measurement_type_id' => $type->id, 'channel_key' => 'probe']);
        $link = HomeAssistantLink::create(['sensor_id' => $sensor->id, 'instance_key' => 'preserved', 'entity_id' => 'sensor.preserved_alert']);
        $sample = Measurement::create(['sensor_id' => $sensor->id, 'home_assistant_link_id' => $link->id, 'value' => '25', 'unit' => 'test', 'observed_at' => now(), 'received_at' => now()]);
        $this->reapplySchemaFrom('2026_11_06_120000_create_monitoring_alerts.php');
        $this->assertDatabaseHas('sensors', ['id' => $sensor->id]);
        $this->assertDatabaseHas('measurements', ['id' => $sample->id]);
        $this->assertDatabaseCount('home_assistant_links', 1);
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('alert_rules', 0);
    }
}
