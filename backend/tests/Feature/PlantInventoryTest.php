<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\LifecycleSetting;
use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\Site;
use App\Models\StorageLocation;
use App\Models\Strain;
use App\Models\User;
use App\Services\Inventory;
use App\Services\LocationAccess;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlantInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private StorageLocation $storage;

    private Plant $plant;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise watering on an explicitly configured stage; sowing is disabled by default.
        $settings = LifecycleSetting::findOrFail(1);
        $stages = $settings->stages;
        $stages[1]['watering'] = true;
        $settings->update(['stages' => $stages]);
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->storage = StorageLocation::create(['name' => 'Care supplies']);
        $this->site = Site::create(['name' => 'Synthetic stock site']);
        $this->actingAs($this->admin);
        $this->plant = app(PlantTracking::class)->create($this->admin, ['name' => 'Synthetic plant', 'strain_id' => Strain::create(['name' => 'Synthetic strain'])->id, 'request_id' => (string) Str::uuid(), 'location' => ['type' => 'site', 'id' => $this->site->id]]);
    }

    private function item(string $type, string $quantity = '100', string $unit = 'ml', ?string $capacity = null): InventoryItem
    {
        $item = InventoryItem::create(['name' => 'Synthetic '.$type, 'type' => $type, 'unit' => $unit, 'capacity_liters' => $capacity]);
        app(Inventory::class)->record($this->admin, $item, ['kind' => 'receipt', 'quantity' => $quantity, 'version' => 1, 'request_id' => (string) Str::uuid(), 'reason' => 'Synthetic opening stock', 'destination' => ['id' => $this->storage->id]]);

        return $item->fresh();
    }

    private function usage(InventoryItem $item, string $quantity, bool $watering = true): array
    {
        return ['inventory_item_id' => $item->id, 'balance_id' => $item->balances()->firstOrFail()->id, 'unit' => $item->unit, $watering ? 'dose_per_liter' : 'quantity' => $quantity];
    }

    private function watering(array $extra = []): array
    {
        return ['version' => $this->plant->fresh()->version, 'request_id' => (string) Str::uuid(), 'with_products' => true, ...$extra];
    }

    private function action(string $id, array $extra = [])
    {
        return $this->postJson('/api/v1/plants/'.$this->plant->id.'/actions', ['action' => $id, 'version' => $this->plant->fresh()->version, 'request_id' => (string) Str::uuid(), ...$extra]);
    }

    public function test_stage_recipes_default_volume_and_action_products_are_validated_and_loaded(): void
    {
        $fertilizer = $this->item('fertilizer');
        $substrate = $this->item('substrate', '100', 'l');
        $pot = $this->item('container', '3', 'piece', '5');
        $settings = LifecycleSetting::findOrFail(1);
        $stages = $settings->stages;
        $stages[1]['water_liters'] = '1.5';
        $stages[1]['fertilizer_periods'] = [['id' => (string) Str::uuid(), 'name' => null, 'unit' => 'days', 'start' => 0, 'end' => null, 'products' => [['inventory_item_id' => $fertilizer->id, 'dose_per_liter' => '2.5']]]];
        $stages[1]['actions'][0]['substrates'] = [['inventory_item_id' => $substrate->id, 'quantity' => '5']];
        $stages[1]['actions'][0]['container_inventory_item_id'] = $pot->id;
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => $settings->version, 'durations' => $settings->durations, 'stages' => $stages])->assertOk();
        $this->getJson('/api/v1/plants/'.$this->plant->id.'/consumables')->assertOk()->assertJsonPath('data.waterLiters', '1.5')->assertJsonPath('data.products.0.quantity', '2.5')->assertJsonPath('data.products.0.balances.0.quantity', '100.000');
        $this->getJson('/api/v1/plants/'.$this->plant->id.'/consumables?action=plant')->assertOk()->assertJsonPath('data.products.0.id', $substrate->id)->assertJsonPath('data.container.capacityLiters', '5.000');
        $stages[1]['fertilizer_periods'][0]['products'][0]['inventory_item_id'] = $substrate->id;
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => $settings->fresh()->version, 'durations' => $settings->durations, 'stages' => $stages])->assertUnprocessable();
        $stages[1]['fertilizer_periods'][0]['products'][0]['inventory_item_id'] = $fertilizer->id;
        $stages[1]['fertilizer_periods'][0]['products'][] = $stages[1]['fertilizer_periods'][0]['products'][0];
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => $settings->fresh()->version, 'durations' => $settings->durations, 'stages' => $stages])->assertUnprocessable();
    }

    public function test_watering_uses_exact_doses_records_history_and_retries_without_double_consumption(): void
    {
        $item = $this->item('fertilizer');
        $payload = $this->watering(['water_liters' => '1.5', 'fertilizers' => [$this->usage($item, '2.333')]]);
        $url = '/api/v1/plants/'.$this->plant->id.'/waterings';
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(96500, $item->fresh()->total_units);
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(96500, $item->fresh()->total_units);
        $this->assertSame(1, PlantEvent::where('kind', 'watered')->count());
        $this->getJson('/api/v1/plants/'.$this->plant->id.'/events')->assertOk()->assertJsonPath('data.0.watering.waterLiters', '1.5')->assertJsonPath('data.0.watering.fertilizers.0.quantity', '3.500');
        $payload['water_liters'] = '2';
        $this->postJson($url, $payload)->assertConflict();
    }

    public function test_insufficient_stock_rolls_back_all_products_watering_and_version(): void
    {
        $one = $this->item('fertilizer', '10');
        $two = $this->item('fertilizer', '1');
        $version = $this->plant->version;
        $this->postJson('/api/v1/plants/'.$this->plant->id.'/waterings', $this->watering(['water_liters' => '2', 'fertilizers' => [$this->usage($one, '2'), $this->usage($two, '2')]]))->assertUnprocessable();
        $this->assertSame(10000, $one->fresh()->total_units);
        $this->assertSame(1000, $two->fresh()->total_units);
        $this->assertSame($version, $this->plant->fresh()->version);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertSame(0, PlantEvent::where('kind', 'watered')->count());
    }

    public function test_wrong_type_unit_source_duplicate_and_without_fertilizer_cannot_deduct(): void
    {
        $item = $this->item('fertilizer');
        $other = $this->item('substrate');
        $usage = $this->usage($item, '2');
        $url = '/api/v1/plants/'.$this->plant->id.'/waterings';
        foreach ([['with_products' => false], ['water_liters' => null], ['fertilizers' => [$usage, $usage]], ['fertilizers' => [$this->usage($other, '1')]], ['fertilizers' => [[...$usage, 'unit' => 'g']]], ['fertilizers' => [[...$usage, 'balance_id' => $other->balances()->first()->id]]]] as $invalid) {
            $this->postJson($url, $this->watering(['water_liters' => '1', 'fertilizers' => [$usage], ...$invalid]))->assertUnprocessable();
        }
        $this->assertSame(100000, $item->fresh()->total_units);
    }

    public function test_substrate_and_pot_change_with_recovery_or_discard_are_atomic_and_idempotent(): void
    {
        $substrate = $this->item('substrate', '30', 'l');
        $small = $this->item('container', '2', 'piece', '1');
        $large = $this->item('container', '2', 'piece', '5');
        $container = fn ($item, $previous = null) => ['inventory_item_id' => $item->id, 'balance_id' => $item->balances()->first()->id, 'previous' => $previous];
        $this->action('plant', ['substrates' => [$this->usage($substrate, '1', false)], 'container' => $container($small)])->assertOk()->assertJsonPath('data.container.capacityLiters', '1.000');
        $payload = ['action' => 'repot_growth', 'version' => $this->plant->fresh()->version, 'request_id' => (string) Str::uuid(), 'substrates' => [$this->usage($substrate, '5', false)], 'container' => $container($large, 'recover')];
        $url = '/api/v1/plants/'.$this->plant->id.'/actions';
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.phase', 'growth')->assertJsonPath('data.container.capacityLiters', '5.000');
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(24000, $substrate->fresh()->total_units);
        $this->assertSame(2000, $small->fresh()->total_units);
        $this->assertSame(1000, $large->fresh()->total_units);
        $this->getJson('/api/v1/plants/'.$this->plant->id.'/events')->assertOk()->assertJsonPath('data.0.substrates.0.quantity', '5.000')->assertJsonPath('data.0.container.previousDisposition', 'recover');
        $this->action('repot_flowering', ['container' => $container($small, 'discard')])->assertOk();
        $this->assertSame(1000, $large->fresh()->total_units);
        $this->assertSame(1000, $small->fresh()->total_units);
    }

    public function test_unavailable_stage_destination_rolls_back_substrate_pot_and_event(): void
    {
        $substrate = $this->item('substrate', '10', 'l');
        $pot = $this->item('container', '2', 'piece', '3');
        $settings = LifecycleSetting::findOrFail(1);
        $stages = $settings->stages;
        $stages[2]['target_location'] = ['type' => 'zone', 'id' => (string) Str::uuid()];
        $settings->update(['automatic_relocation' => true, 'stages' => $stages]);
        $movementCount = InventoryMovement::count();
        $this->action('plant', ['substrates' => [$this->usage($substrate, '3', false)], 'container' => ['inventory_item_id' => $pot->id, 'balance_id' => $pot->balances()->first()->id, 'previous' => null]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'plants.errors.relocationUnavailable');
        $this->assertSame(10000, $substrate->fresh()->total_units);
        $this->assertSame(2000, $pot->fresh()->total_units);
        $this->assertSame($movementCount, InventoryMovement::count());
        $this->assertSame('sowing', $this->plant->fresh()->phase);
        $this->assertSame(1, $this->plant->fresh()->version);
        $this->assertSame(1, $this->plant->events()->count());
    }

    public function test_missing_old_pot_disposition_rolls_back_substrate_and_new_pot(): void
    {
        $item = $this->item('container', '2', 'piece', '3');
        $substrate = $this->item('substrate', '10', 'l');
        $selection = ['inventory_item_id' => $item->id, 'balance_id' => $item->balances()->first()->id, 'previous' => null];
        $this->action('plant', ['container' => $selection])->assertOk();
        $this->action('repot_growth', ['container' => $selection, 'substrates' => [$this->usage($substrate, '3', false)]])->assertUnprocessable();
        $this->assertSame(10000, $substrate->fresh()->total_units);
        $this->assertSame(1000, $item->fresh()->total_units);
        $this->assertSame('germination', $this->plant->fresh()->phase);
    }

    public function test_inventory_permission_and_location_scope_are_enforced(): void
    {
        $item = $this->item('fertilizer');
        $user = User::factory()->create(['inventory_location_access' => [$this->storage->id], 'permission_grants' => ['plants.manage'], 'location_access' => [['type' => 'site', 'id' => $this->site->id]]]);
        $this->actingAs($user)->postJson('/api/v1/plants/'.$this->plant->id.'/waterings', $this->watering(['water_liters' => '1', 'fertilizers' => [$this->usage($item, '2')]]))->assertForbidden();
        $this->assertSame(100000, $item->fresh()->total_units);
        $outside = StorageLocation::create(['name' => 'Synthetic hidden stock']);
        $user->forceFill(['permission_grants' => ['plants.manage', 'inventory.manage']])->save();
        $item->balances()->first()->update(['storage_location_id' => $outside->id]);
        app(LocationAccess::class)->clear();
        $input = ['inventory_item_id' => $item->id, 'balance_id' => $item->balances()->withoutGlobalScopes()->first()->id, 'unit' => 'ml', 'dose_per_liter' => '1'];
        $this->actingAs($user->fresh())->postJson('/api/v1/plants/'.$this->plant->id.'/waterings', $this->watering(['water_liters' => '1', 'fertilizers' => [$input]]))->assertUnprocessable();
        $this->assertSame(100000, $item->fresh()->total_units);
    }
}
