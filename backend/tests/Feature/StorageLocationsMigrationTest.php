<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Site;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorageLocationsMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        DB::table('inventory_movements')->delete();
        DB::table('inventory_balances')->delete();
        DB::table('storage_locations')->delete();
        parent::tearDown();
    }

    public function test_existing_stock_movement_bytes_and_scoped_rights_survive_round_trip(): void
    {
        $migration = require database_path('migrations/2026_11_15_120000_separate_inventory_storage_locations.php');
        $migration->down();
        $site = Site::create(['name' => 'Old site']);
        $room = $site->rooms()->create(['name' => 'Old stock room']);
        $zone = $room->zones()->create(['name' => 'Old shelf']);
        $user = User::factory()->create(['location_access' => [['type' => 'room', 'id' => $room->id]]]);
        $none = User::factory()->create(['location_access' => []]);
        $item = InventoryItem::create(['name' => 'Preserved seeds', 'type' => 'seeds', 'unit' => 'piece']);
        $item->forceFill(['total_units' => 5000, 'version' => 2, 'unit_locked' => true])->save();
        $balanceId = (string) Str::uuid();
        DB::table('inventory_balances')->insert(['id' => $balanceId, 'inventory_item_id' => $item->id, 'location_kind' => 'zone', 'location_uuid' => $zone->id, 'site_id' => $site->id, 'room_id' => $room->id, 'zone_id' => $zone->id, 'quantity_units' => 5000, 'created_at' => now(), 'updated_at' => now()]);
        $movement = InventoryMovement::create(['inventory_item_id' => $item->id, 'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'original request'), 'version' => 2, 'kind' => 'receipt', 'quantity_units' => 5000, 'reason' => 'Original stock', 'item_name' => $item->name, 'unit' => 'piece', 'destination_balance_id' => $balanceId, 'destination_snapshot' => ['type' => 'zone', 'id' => $zone->id, 'site' => ['id' => $site->id, 'name' => 'Original frozen site'], 'room' => ['id' => $room->id, 'name' => $room->name], 'zone' => ['id' => $zone->id, 'name' => $zone->name]], 'destination_before' => 0, 'destination_after' => 5000, 'total_after' => 5000, 'actor_uuid' => $user->uuid, 'recorded_by' => $user->id, 'recorded_at' => now()]);
        $originalMovement = $movement->fresh()->getAttributes();
        $originalItem = $item->fresh()->getAttributes();
        $originalBalance = (array) DB::table('inventory_balances')->where('id', $balanceId)->first();
        $migration->up();
        $storage = StorageLocation::sole();
        $this->assertSame('Old site / Old stock room / Old shelf', $storage->name);
        $this->assertSame([$storage->id], $user->fresh()->inventory_location_access);
        $this->assertSame([], $none->fresh()->inventory_location_access);
        $this->assertSame($originalItem, $item->fresh()->getAttributes());
        $this->assertSame($originalMovement, $movement->fresh()->getAttributes());
        $this->assertDatabaseHas('inventory_balances', ['id' => $balanceId, 'storage_location_id' => $storage->id, 'quantity_units' => 5000]);
        $migration->down();
        $this->assertSame($originalBalance, (array) DB::table('inventory_balances')->where('id', $balanceId)->first());
        $this->assertSame($originalMovement, $movement->fresh()->getAttributes());
        $migration->up();
        $this->assertSame($originalMovement, $movement->fresh()->getAttributes());
        $this->assertSame($originalItem, $item->fresh()->getAttributes());
        $zone->delete();
        $this->assertDatabaseCount('inventory_balances', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_rollback_refuses_to_discard_new_independent_locations(): void
    {
        StorageLocation::create(['name' => 'Independent cabinet']);
        $migration = require database_path('migrations/2026_11_15_120000_separate_inventory_storage_locations.php');
        try {
            $migration->down();
            $this->fail('Independent locations must be retained.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Retain independent storage', $error->getMessage());
        }
        $this->assertDatabaseCount('storage_locations', 1);
    }
}
