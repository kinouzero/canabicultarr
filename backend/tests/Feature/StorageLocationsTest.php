<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Site;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\Inventory;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorageLocationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function movement(User $actor, InventoryItem $item, StorageLocation $location, array $data = []): InventoryMovement
    {
        return app(Inventory::class)->record($actor, $item, ['kind' => 'receipt', 'quantity' => '5', 'version' => $item->fresh()->version, 'request_id' => (string) Str::uuid(), 'reason' => 'Test operation', 'destination' => ['id' => $location->id], ...$data]);
    }

    public function test_administrator_can_set_independent_stock_grants_and_revoke_old_sessions(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $location = StorageLocation::create(['name' => 'Granted cabinet']);
        $version = $target->session_version;
        $input = ['name' => $target->name, 'email' => $target->email, 'is_active' => true, 'permission_role_ids' => $target->permission_role_ids, 'inventory_location_access' => [$location->id], 'location_access' => []];
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$target->uuid, $input)->assertOk()->assertJsonPath('data.inventoryLocationAccess.0.id', $location->id)->assertJsonPath('data.locationAccess', []);
        $this->assertSame([$location->id], $target->fresh()->inventory_location_access);
        $this->assertGreaterThan($version, $target->fresh()->session_version);
        $input['inventory_location_access'] = [(string) Str::uuid()];
        $this->patchJson('/api/v1/users/'.$target->uuid, $input)->assertUnprocessable()->assertJsonValidationErrors('inventory_location_access');
        $this->assertSame([$location->id], $target->fresh()->inventory_location_access);
    }

    public function test_storage_locations_need_no_cultivation_node_and_keep_exact_atomic_stock(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $location = $this->postJson('/api/v1/inventory/locations', ['name' => 'Seed cabinet'])->assertCreated()->json('data');
        $stock = StorageLocation::findOrFail($location['id']);
        $this->assertDatabaseCount('sites', 0);
        $item = app(Inventory::class)->save($admin, ['name' => 'Seeds', 'type' => 'seeds', 'unit' => 'piece']);
        $receipt = $this->movement($admin, $item, $stock);
        $this->assertSame(5000, $item->fresh()->total_units);
        $this->assertSame(['id' => $stock->id, 'name' => 'Seed cabinet'], $receipt->destination_snapshot);
        $this->getJson('/api/v1/inventory/items/'.$item->id.'/balances')->assertOk()->assertJsonPath('data.0.location.name', 'Seed cabinet');
        $this->patchJson('/api/v1/inventory/locations/'.$stock->id, ['name' => 'New name'])->assertOk();
        $this->assertSame('Seed cabinet', $receipt->fresh()->destination_snapshot['name']);
        $this->patchJson('/api/v1/inventory/locations/'.$stock->id, ['name' => 'New name', 'is_archived' => true])->assertConflict();
        $this->assertFalse($stock->fresh()->is_archived);
        $this->movement($admin, $item, $stock, ['kind' => 'count', 'quantity' => '0']);
        $this->patchJson('/api/v1/inventory/locations/'.$stock->id, ['name' => 'New name', 'is_archived' => true])->assertOk();
        $replay = app(Inventory::class)->record($admin, $item, ['kind' => 'receipt', 'quantity' => '5', 'version' => 1, 'request_id' => $receipt->request_id, 'reason' => 'Test operation', 'destination' => ['id' => $stock->id]]);
        $this->assertSame($receipt->id, $replay->id);
        $this->assertSame(0, $item->fresh()->total_units);
        $this->assertDatabaseCount('inventory_movements', 2);

    }

    public function test_storage_rights_are_independent_and_shared_transfers_require_both_sides(): void
    {
        $admin = $this->admin();
        $first = StorageLocation::create(['name' => 'Allowed stock']);
        $second = StorageLocation::create(['name' => 'Private stock']);
        $item = app(Inventory::class)->save($admin, ['name' => 'Supply', 'unit' => 'ml']);
        $this->movement($admin, $item, $first);
        $this->movement($admin, $item, $second);
        $site = Site::create(['name' => 'Unrelated cultivation']);
        $reader = User::factory()->create(['location_access' => [], 'inventory_location_access' => [$first->id], 'permission_grants' => ['inventory.manage']]);
        $this->actingAs($reader);
        $this->getJson('/api/v1/inventory/locations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/locations/'.$second->id)->assertNotFound();
        $this->getJson('/api/v1/inventory/items/'.$item->id)->assertOk()->assertJsonPath('data.totalQuantity', '5.000')->assertJsonPath('data.threshold', null);
        $this->getJson('/api/v1/inventory/items/'.$item->id.'/movements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.totalAfter', null);
        $this->postJson('/api/v1/inventory/locations', ['name' => 'Unauthorized'])->assertForbidden();
        $this->postJson('/api/v1/inventory/items/'.$item->id.'/movements', ['kind' => 'transfer', 'source' => ['id' => $first->id], 'destination' => ['id' => $second->id], 'quantity' => '1', 'version' => $item->fresh()->version, 'request_id' => (string) Str::uuid(), 'reason' => 'Forbidden'])->assertNotFound();
        $this->assertSame(10000, $item->fresh()->total_units);
        $this->actingAs($admin)->postJson('/api/v1/inventory/items/'.$item->id.'/movements', ['kind' => 'transfer', 'source' => ['id' => $first->id], 'destination' => ['id' => $second->id], 'quantity' => '1', 'version' => $item->fresh()->version, 'request_id' => (string) Str::uuid(), 'reason' => 'Authorized cross-boundary transfer'])->assertCreated();
        $this->actingAs($reader)->getJson('/api/v1/inventory/items/'.$item->id.'/movements')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['name' => 'Private stock']);
        $this->getJson('/api/v1/inventory/items/'.$item->id)->assertOk()->assertJsonPath('data.totalQuantity', '4.000');

        Auth::forgetGuards();
        $reader->forceFill(['location_access' => [['type' => 'site', 'id' => $site->id]], 'inventory_location_access' => []])->save();
        $this->actingAs($reader);
        $this->getJson('/api/v1/inventory/items/'.$item->id)->assertOk()->assertJsonPath('data.totalQuantity', '0.000');
        $this->getJson('/api/v1/inventory/items/'.$item->id.'/balances')->assertOk()->assertJsonCount(0, 'data');
    }
}
