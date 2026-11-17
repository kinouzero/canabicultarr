<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\MeasurementType;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_uuid_search_restores_references_without_bypassing_scope_or_item_filters(): void
    {
        $site = Site::create(['name' => 'Selected site']);
        $hidden = Site::create(['name' => 'Other site']);
        $type = MeasurementType::create(['name' => 'Selected type', 'code' => 'selected-type', 'unit' => '%']);
        $item = InventoryItem::create(['name' => 'Selected container', 'unit' => 'piece', 'type' => 'container', 'decimal_places' => 0]);
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $this->actingAs($reader)->getJson('/api/v1/sites?search='.$site->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Selected site');
        $this->getJson('/api/v1/sites?search='.$hidden->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/measurement-types?search='.$type->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Selected type');
        $this->getJson('/api/v1/inventory/items?type=container&search='.$item->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Selected container');
        $this->getJson('/api/v1/inventory/items?type=seeds&search='.$item->id)->assertOk()->assertJsonCount(0, 'data');
        $item->update(['is_archived' => true]);
        $this->getJson('/api/v1/inventory/items?search='.$item->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/items?state=archived&search='.$item->id)->assertOk()->assertJsonCount(1, 'data');
    }
}
