<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\StorageLocation;
use App\Models\Strain;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StrainSeedStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_seed_stock_uses_current_inventory_and_geographic_scope(): void
    {
        Cache::flush();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $a = StorageLocation::create(['name' => 'Visible Seed Site']);
        $b = StorageLocation::create(['name' => 'Hidden Seed Site']);
        $item = new InventoryItem(['name' => 'Fictional Seeds', 'type' => 'seeds', 'unit' => 'piece']);
        $item->total_units = 11000;
        $item->save();
        foreach ([[$a, 3000], [$b, 8000]] as [$site, $quantity]) {
            InventoryBalance::create(['inventory_item_id' => $item->id, 'storage_location_id' => $site->id, 'quantity_units' => $quantity]);
        }
        $created = $this->actingAs($admin)->postJson('/api/v1/strains', ['name' => 'Fictional Seed Strain', 'seed_inventory_item_id' => $item->id])->assertCreated()->assertJsonPath('data.seedStock.quantity', '11.000');
        $id = $created->json('data.id');
        $this->getJson('/api/v1/strains')->assertJsonPath('data.0.seedStock.quantity', '11.000');
        $restricted = User::factory()->create(['inventory_location_access' => [$a->id]]);
        $this->actingAs($restricted)->getJson('/api/v1/strains/'.$id)->assertJsonPath('data.seedStock.quantity', '3.000')->assertJsonPath('data.seedStock.scoped', true);
        $this->getJson('/api/v1/strains')->assertJsonPath('data.0.seedStock.quantity', '3.000');
        $this->actingAs($admin)->patchJson('/api/v1/strains/'.$id, ['version' => 1, 'seed_inventory_item_id' => null])->assertOk()->assertJsonPath('data.seedStock', null);
        $this->assertSame(11000, $item->fresh()->total_units);
    }

    public function test_stock_sort_uses_the_same_accessible_quantity_as_the_cards(): void
    {
        Cache::flush();
        $visible = StorageLocation::create(['name' => 'Visible']);
        $hidden = StorageLocation::create(['name' => 'Hidden']);
        $ids = [];
        foreach ([[20000, 1000], [10000, 8000]] as $index => [$total, $local]) {
            $item = new InventoryItem(['name' => 'Seeds '.$index, 'type' => 'seeds', 'unit' => 'piece']);
            $item->total_units = $total;
            $item->save();
            foreach ([[$visible, $local], [$hidden, $total - $local]] as [$site, $quantity]) {
                InventoryBalance::create(['inventory_item_id' => $item->id, 'storage_location_id' => $site->id, 'quantity_units' => $quantity]);
            }
            $ids[] = Strain::create(['name' => 'Strain '.$index, 'seed_inventory_item_id' => $item->id])->id;
        }
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]))->getJson('/api/v1/strains?sort=stock_desc')->assertOk()->assertJsonPath('data.0.id', $ids[0]);
        $member = User::factory()->create(['inventory_location_access' => [$visible->id]]);
        $this->actingAs($member)->getJson('/api/v1/strains?sort=stock_desc')->assertOk()->assertJsonPath('data.0.id', $ids[1])->assertJsonPath('data.0.seedStock.quantity', '8.000');
        $this->getJson('/api/v1/strains?sort=stock_asc')->assertJsonPath('data.0.id', $ids[0]);
    }

    public function test_seed_links_reject_non_piece_and_duplicate_inventory_items(): void
    {
        Cache::flush();
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $ordinary = InventoryItem::create(['name' => 'Ordinary pieces', 'unit' => 'piece']);
        $this->postJson('/api/v1/strains', ['name' => 'Wrong Category', 'seed_inventory_item_id' => $ordinary->id])->assertUnprocessable()->assertJsonValidationErrors('seed_inventory_item_id');
        $wrong = InventoryItem::create(['name' => 'Liquid', 'unit' => 'ml']);
        $this->postJson('/api/v1/strains', ['name' => 'Wrong Seed Link', 'seed_inventory_item_id' => $wrong->id])->assertUnprocessable();
        $stock = InventoryItem::create(['name' => 'Seeds', 'type' => 'seeds', 'unit' => 'piece']);
        $this->postJson('/api/v1/strains', ['name' => 'First Seed Link', 'seed_inventory_item_id' => $stock->id])->assertCreated();
        $this->patchJson('/api/v1/inventory/items/'.$stock->id, ['type' => 'other', 'version' => $stock->version])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame('seeds', $stock->fresh()->type);
        $this->postJson('/api/v1/strains', ['name' => 'Second Seed Link', 'seed_inventory_item_id' => $stock->id])->assertUnprocessable();
    }

    public function test_migration_recognizes_only_exact_import_provenance_without_changing_stock(): void
    {
        $strain = Strain::create(['name' => 'Imported Seed Strain']);
        $item = new InventoryItem(['name' => 'Imported Seeds', 'type' => 'seeds', 'unit' => 'piece', 'sku' => 'trello-fictional', 'description' => 'Seed stock imported as an opening count, without replaying historical seed consumption. Variety: '.$strain->id."\nhttps://example.test/source"]);
        $item->total_units = 7000;
        $item->save();
        $rename = require database_path('migrations/2026_11_14_180000_rename_varieties_to_strains.php');
        $rename->down();
        $migration = require database_path('migrations/2026_11_08_080000_link_variety_seed_inventory.php');
        $migration->down();
        $migration->up();
        $rename->up();
        $this->assertSame($item->id, $strain->fresh()->seed_inventory_item_id);
        $this->assertSame(7000, $item->fresh()->total_units);
    }
}
