<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Site;
use App\Models\StorageLocation;
use App\Models\Strain;
use App\Models\User;
use App\Services\DocumentLibrary;
use App\Services\Inventory;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_fertilizer_default_dose_is_editable_validated_and_authorized(): void
    {
        $this->actingAs($this->admin());
        $id = $this->postJson('/api/v1/inventory/items', ['name' => 'Liquid feed', 'type' => 'fertilizer', 'unit' => 'ml', 'default_dose_per_liter' => '2.5'])->assertCreated()->assertJsonPath('data.defaultDosePerLiter', '2.500')->json('data.id');
        foreach (['0', '-1', '1.2345', '1000001', 'invalid'] as $dose) {
            $this->patchJson('/api/v1/inventory/items/'.$id, ['version' => 1, 'default_dose_per_liter' => $dose])->assertUnprocessable()->assertJsonValidationErrors('default_dose_per_liter');
        }
        $this->postJson('/api/v1/inventory/items', ['name' => 'Invalid supply', 'type' => 'substrate', 'unit' => 'l', 'default_dose_per_liter' => '2'])->assertUnprocessable();
        $this->patchJson('/api/v1/inventory/items/'.$id, ['version' => 1, 'default_dose_per_liter' => null])->assertOk()->assertJsonPath('data.defaultDosePerLiter', null);
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/inventory/items/'.$id, ['version' => 2, 'default_dose_per_liter' => '3'])->assertForbidden();
        $this->assertNull(InventoryItem::findOrFail($id)->default_dose_per_liter);
    }

    public function test_item_types_filter_seed_stock_and_validate_seed_units(): void
    {
        $this->actingAs($this->admin());
        $seed = $this->postJson('/api/v1/inventory/items', ['name' => 'Seed packet', 'type' => 'seeds', 'unit' => 'piece'])->assertCreated()->assertJsonPath('data.type', 'seeds')->assertJsonPath('data.decimalPlaces', 0)->json('data.id');
        $this->postJson('/api/v1/inventory/items', ['name' => 'Plant pot', 'type' => 'container', 'unit' => 'piece'])->assertCreated();
        $this->postJson('/api/v1/inventory/items', ['name' => 'Uncategorized', 'unit' => 'piece'])->assertCreated()->assertJsonPath('data.type', 'other');
        $this->getJson('/api/v1/inventory/items?type=seeds')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $seed);
        $this->getJson('/api/v1/inventory/items?type=invalid')->assertUnprocessable();
        $this->postJson('/api/v1/inventory/items', ['name' => 'Invalid type', 'type' => 'invalid', 'unit' => 'piece'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/v1/inventory/items', ['name' => 'Invalid seed unit', 'type' => 'seeds', 'unit' => 'g'])->assertUnprocessable()->assertJsonValidationErrors('unit');
        $this->patchJson('/api/v1/inventory/items/'.$seed, ['type' => 'other', 'version' => 1])->assertOk()->assertJsonPath('data.type', 'other');
        $this->getJson('/api/v1/inventory/items?type=seeds')->assertJsonCount(0, 'data');
    }

    public function test_type_migration_classifies_only_existing_strain_stock_links(): void
    {
        $linked = InventoryItem::create(['name' => 'Linked stock', 'unit' => 'piece']);
        $other = InventoryItem::create(['name' => 'Unlinked stock', 'unit' => 'piece']);
        Strain::create(['name' => 'Seed strain', 'seed_inventory_item_id' => $linked->id]);
        $this->reapplySchemaFrom('2026_11_10_220000_add_inventory_item_types.php');
        $this->assertSame('seeds', $linked->fresh()->type);
        $this->assertSame('other', $other->fresh()->type);
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function item(User $admin, array $data = []): InventoryItem
    {
        return app(Inventory::class)->save($admin, ['name' => 'Test Supply', 'unit' => 'kg', ...$data]);
    }

    private function location(string $name = 'Test Warehouse'): array
    {
        $site = StorageLocation::create(['name' => $name]);

        return ['id' => $site->id];
    }

    private function input(InventoryItem $item, array $destination, array $data = []): array
    {
        return ['kind' => 'receipt', 'quantity' => '10.125', 'reason' => 'Test receipt', 'version' => $item->fresh()->version, 'request_id' => (string) Str::uuid(), 'destination' => $destination, ...$data];
    }

    private function move(User $actor, InventoryItem $item, array $destination, array $data = [])
    {
        return app(Inventory::class)->record($actor, $item, $this->input($item, $destination, $data));
    }

    public function test_display_precision_defaults_persists_and_never_changes_exact_stock(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $seed = $this->postJson('/api/v1/inventory/items', ['name' => 'Seed stock', 'unit' => 'piece'])->assertCreated()->assertJsonPath('data.decimalPlaces', 0)->json('data');
        $item = $this->item($admin);
        $this->getJson('/api/v1/inventory/items/'.$item->id)->assertOk()->assertJsonPath('data.decimalPlaces', 3);
        $location = $this->location();
        $this->move($admin, $item, $location, ['quantity' => '10.125']);
        $url = '/api/v1/inventory/items/'.$item->id;
        $this->patchJson($url, ['version' => $item->fresh()->version, 'decimal_places' => 1])->assertOk()->assertJsonPath('data.decimalPlaces', 1)->assertJsonPath('data.totalQuantity', '10.125');
        $item->refresh();
        $this->assertSame(10125, $item->total_units);
        $this->assertSame(10125, $item->balances()->firstOrFail()->quantity_units);
        $this->assertSame(10125, $item->movements()->firstOrFail()->quantity_units);
        foreach ([-1, 4, 1.5, null] as $invalid) {
            $this->patchJson($url, ['version' => $item->version, 'decimal_places' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('decimal_places');
        }
        $this->patchJson($url, ['version' => $item->version, 'decimal_places' => 0])->assertOk()->assertJsonPath('data.decimalPlaces', 0);
        $this->postJson('/api/v1/inventory/items', ['name' => 'Explicit precision', 'unit' => 'kg', 'decimal_places' => 2])->assertCreated()->assertJsonPath('data.decimalPlaces', 2);
        $this->assertSame(0, InventoryItem::findOrFail($seed['id'])->decimal_places);
    }

    public function test_guests_cannot_read_or_write_inventory(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $url = '/api/v1/inventory/items/'.$item->id;
        foreach (['/api/v1/inventory/items', $url, $url.'/balances', $url.'/movements'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $this->postJson('/api/v1/inventory/items', ['name' => 'Denied', 'unit' => 'kg'])->assertUnauthorized();
        $this->patchJson($url, ['version' => 1, 'name' => 'Denied'])->assertUnauthorized();
        $this->postJson($url.'/movements', [])->assertUnauthorized();
    }

    public function test_members_read_but_only_active_administrators_write(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $item = $this->item($admin);
        $location = $this->location();
        $this->move($admin, $item, $location);
        $url = '/api/v1/inventory/items/'.$item->id;
        $this->actingAs($member)->getJson($url)->assertOk()->assertJsonPath('data.canManage', false);
        $this->getJson($url.'/balances')->assertOk();
        $this->getJson($url.'/movements')->assertOk();
        $this->postJson('/api/v1/inventory/items', ['name' => 'Denied', 'unit' => 'kg'])->assertForbidden();
        $this->patchJson($url, ['version' => 2, 'name' => 'Denied'])->assertForbidden();
        $this->postJson($url.'/movements', $this->input($item, $location))->assertForbidden();
        $admin->fresh()->permissionRoles()->sync([AccessProfileSeeder::defaultProfile()->id]);
        try {
            $this->move($admin, $item, $location);
            $this->fail('Stale administrator accepted');
        } catch (AuthorizationException) {
            $this->assertSame(10125, $item->fresh()->total_units);
        }
        $member->forceFill(['session_version' => 1])->save();
        $this->actingAs($member)->withSession(['auth_version' => 0])->getJson($url)->assertUnauthorized();
    }

    public function test_catalog_assigns_uuid_and_never_accepts_opening_balances(): void
    {
        $admin = $this->admin();
        $uuid = (string) Str::uuid();
        $data = $this->actingAs($admin)->postJson('/api/v1/inventory/items', ['name' => ' Test Supply ', 'unit' => 'kg', 'sku' => ' ABC-1 ', 'threshold' => '2.5', 'id' => $uuid, 'total_units' => 9000, 'totalQuantity' => '99', 'version' => 90, 'unit_locked' => true, 'is_archived' => true])->assertCreated()
            ->assertJsonPath('data.totalQuantity', '0.000')->assertJsonPath('data.threshold', '2.500')->assertJsonPath('data.version', 1)->assertJsonPath('data.unitLocked', false)->assertJsonPath('data.isArchived', false)->assertJsonPath('data.isLowStock', true)->assertJsonPath('data.sku', 'ABC-1')->assertJsonMissingPath('data.sku_key')->json('data');
        $this->assertTrue(Str::isUuid($data['id']));
        $this->assertNotSame($uuid, $data['id']);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_sku_is_case_insensitive_unique_and_optional(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin, ['sku' => 'ABC']);
        $this->actingAs($admin)->postJson('/api/v1/inventory/items', ['name' => 'Duplicate', 'unit' => 'piece', 'sku' => 'abc'])->assertConflict()->assertJsonPath('error.code', 'inventory.errors.skuTaken');
        $this->patchJson('/api/v1/inventory/items/'.$item->id, ['version' => 1, 'sku' => 'abc'])->assertOk();
        $this->item($admin);
        $this->item($admin);
        $this->assertDatabaseCount('inventory_items', 3);
    }

    public function test_receipt_issue_and_transfer_preserve_exact_quantities_and_history(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $first = $this->location();
        $second = $this->location('Second Warehouse');
        $url = '/api/v1/inventory/items/'.$item->id;
        $this->actingAs($admin)->postJson($url.'/movements', $this->input($item, $first))->assertCreated()->assertJsonPath('data.quantity', '10.125')->assertJsonPath('data.destinationBefore', '0.000')->assertJsonPath('data.destinationAfter', '10.125')->assertJsonPath('data.totalAfter', '10.125');
        $this->move($admin, $item, $second, ['kind' => 'transfer', 'source' => $first, 'quantity' => '3.001']);
        $this->move($admin, $item, $second, ['kind' => 'issue', 'source' => $second, 'destination' => null, 'quantity' => '0.001']);
        $this->assertSame(10124, $item->fresh()->total_units);
        $this->assertSame(7124, $item->balances()->where('storage_location_id', $first['id'])->sole()->quantity_units);
        $this->assertSame(3000, $item->balances()->where('storage_location_id', $second['id'])->sole()->quantity_units);
        $this->getJson($url.'/movements')->assertJsonCount(3, 'data')->assertJsonPath('data.0.sourceBefore', '3.001')->assertJsonPath('data.0.sourceAfter', '3.000')->assertJsonMissingPath('data.0.request_hash')->assertJsonMissingPath('data.0.actor.email');
        $this->getJson($url.'/balances')->assertJsonCount(2, 'data');
        $this->assertSame(4, $item->fresh()->version);
    }

    public function test_no_floating_point_rounding_across_small_receipts(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        foreach (['0.1', '0.2', '0.003'] as $quantity) {
            $this->move($admin, $item, $location, ['quantity' => $quantity]);
        }
        $this->actingAs($admin)->getJson('/api/v1/inventory/items/'.$item->id)->assertJsonPath('data.totalQuantity', '0.303');
        $this->assertSame(303, $item->balances()->sole()->quantity_units);
    }

    public function test_physical_counts_set_absolute_local_stock_and_retain_previous_quantities(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $first = $this->location();
        $second = $this->location('Second Warehouse');
        $this->move($admin, $item, $first);
        $this->move($admin, $item, $second, ['quantity' => '2']);
        $count = $this->move($admin, $item, $first, ['kind' => 'count', 'quantity' => '7.5']);
        $this->assertSame(10125, $count->destination_before);
        $this->assertSame(7500, $count->destination_after);
        $this->assertSame(9500, $item->fresh()->total_units);
        $this->move($admin, $item, $first, ['kind' => 'count', 'quantity' => '0']);
        $this->assertSame(2000, $item->fresh()->total_units);
        $this->assertDatabaseCount('inventory_movements', 4);
    }

    public function test_insufficient_stock_rolls_back_every_balance_and_history_change(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $first = $this->location();
        $second = $this->location('Second Warehouse');
        $this->move($admin, $item, $first, ['quantity' => '1']);
        $this->actingAs($admin)->postJson('/api/v1/inventory/items/'.$item->id.'/movements', $this->input($item, $second, ['kind' => 'transfer', 'source' => $first, 'quantity' => '1.001']))->assertConflict()->assertJsonPath('error.code', 'inventory.errors.insufficientStock');
        $this->assertSame(1000, $item->fresh()->total_units);
        $this->assertDatabaseCount('inventory_balances', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(2, $item->fresh()->version);
    }

    public function test_decimal_and_piece_validation_rejects_ambiguous_or_invalid_inputs(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin, ['unit' => 'piece']);
        $location = $this->location();
        $this->actingAs($admin);
        $url = '/api/v1/inventory/items/'.$item->id.'/movements';
        foreach (['-1', '1e3', '1,2', '0.0001', '1000000000', 1.5, 'NaN', '', true, '0', '0.5'] as $quantity) {
            $this->postJson($url, $this->input($item, $location, ['quantity' => $quantity]))->assertUnprocessable();
        }
        $this->postJson($url, $this->input($item, $location, ['kind' => 'count', 'quantity' => '0']))->assertCreated();
        $this->patchJson('/api/v1/inventory/items/'.$item->id, ['version' => 2, 'threshold' => '0.5'])->assertUnprocessable()->assertJsonPath('errors.threshold.0', 'inventory.validation.wholePieces');
        $this->assertSame(0, $item->fresh()->total_units);
    }

    public function test_total_limit_is_inclusive_and_does_not_overflow(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        $this->move($admin, $item, $location, ['quantity' => '999999999.999']);
        $this->actingAs($admin)->postJson('/api/v1/inventory/items/'.$item->id.'/movements', $this->input($item, $location, ['quantity' => '0.001']))->assertConflict()->assertJsonPath('error.code', 'inventory.errors.quantityLimit');
        $this->assertSame(999999999999, $item->fresh()->total_units);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_versions_prevent_stale_metadata_and_stock_writes(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        $payload = $this->input($item, $location);
        $this->move($admin, $item, $location);
        $url = '/api/v1/inventory/items/'.$item->id;
        $this->actingAs($admin)->patchJson($url, ['version' => 1, 'name' => 'Stale'])->assertConflict();
        $this->postJson($url.'/movements', $payload)->assertConflict()->assertJsonPath('error.code', 'inventory.errors.conflict');
        $this->patchJson($url, ['version' => 2, 'name' => 'Test Supply'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($url, ['name' => 'No version'])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_retries_return_the_original_movement_without_changing_stock_or_logs(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        $payload = $this->input($item, $location);
        $url = '/api/v1/inventory/items/'.$item->id.'/movements';
        $this->actingAs($admin);
        $id = $this->postJson($url, $payload)->assertCreated()->json('data.id');
        $this->move($admin, $item, $location, ['quantity' => '1']);
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.totalAfter', '10.125');
        $this->assertSame(11125, $item->fresh()->total_units);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->postJson($url, [...$payload, 'quantity' => '11'])->assertConflict()->assertJsonPath('error.code', 'inventory.errors.requestConflict');
        $this->actingAs($this->admin())->postJson($url, $payload)->assertConflict()->assertJsonPath('error.code', 'inventory.errors.requestConflict');
    }

    public function test_unit_is_locked_forever_after_first_movement_even_when_stock_returns_to_zero(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        $this->actingAs($admin)->patchJson('/api/v1/inventory/items/'.$item->id, ['version' => 1, 'unit' => 'g'])->assertOk()->assertJsonPath('data.unit', 'g');
        $this->move($admin, $item, $location, ['kind' => 'count', 'quantity' => '0']);
        $this->patchJson('/api/v1/inventory/items/'.$item->id, ['version' => 3, 'unit' => 'kg'])->assertConflict()->assertJsonPath('error.code', 'inventory.errors.unitLocked');
        $this->assertTrue($item->fresh()->unit_locked);
    }

    public function test_archive_requires_zero_stock_and_blocks_new_movements_until_restored(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        $this->move($admin, $item, $location);
        $url = '/api/v1/inventory/items/'.$item->id;
        $this->actingAs($admin);
        $this->patchJson($url, ['version' => 2, 'is_archived' => true])->assertConflict()->assertJsonPath('error.code', 'inventory.errors.stockRemaining');
        $this->move($admin, $item, $location, ['kind' => 'count', 'quantity' => '0']);
        $this->patchJson($url, ['version' => 3, 'is_archived' => true])->assertOk();
        $this->getJson('/api/v1/inventory/items')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/items?state=archived')->assertJsonCount(1, 'data');
        $this->postJson($url.'/movements', $this->input($item, $location))->assertConflict()->assertJsonPath('error.code', 'inventory.errors.archived');
        $this->getJson($url.'/movements')->assertJsonCount(2, 'data');
        $this->patchJson($url, ['version' => 4, 'is_archived' => false])->assertOk();
        $this->move($admin, $item, $location);
        $this->assertSame(10125, $item->fresh()->total_units);
    }

    public function test_location_validation_and_failed_transfers_create_no_orphan_balances(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        $url = '/api/v1/inventory/items/'.$item->id.'/movements';
        $this->actingAs($admin);
        $this->postJson($url, $this->input($item, ['id' => (string) Str::uuid()]))->assertNotFound();
        $this->postJson($url, $this->input($item, $location, ['kind' => 'transfer', 'source' => $location]))->assertUnprocessable()->assertJsonPath('errors.destination.0', 'inventory.validation.differentLocations');
        $this->postJson($url, $this->input($item, $location, ['kind' => 'issue']))->assertUnprocessable();
        $this->postJson($url, $this->input($item, $location, ['source' => $location]))->assertUnprocessable();
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_storage_balances_and_immutable_snapshots_use_server_resolved_names(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = StorageLocation::create(['name' => 'Original storage']);
        $move = $this->move($admin, $item, ['id' => $location->id]);
        $this->assertSame('Original storage', $move->destination_snapshot['name']);
        $location->update(['name' => 'Renamed storage']);
        $this->actingAs($admin)->getJson('/api/v1/inventory/items/'.$item->id.'/balances')->assertJsonPath('data.0.location.name', 'Renamed storage');
        $this->getJson('/api/v1/inventory/items/'.$item->id.'/movements')->assertJsonPath('data.0.destination.name', 'Original storage');
        $this->assertDatabaseHas('inventory_balances', ['storage_location_id' => $location->id]);
    }

    public function test_search_pagination_and_threshold_indicator_are_current_and_literal(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 22; $i++) {
            $this->item($admin, ['name' => $i === 0 ? '100%_Supply' : 'Supply '.$i, 'threshold' => $i === 0 ? '2' : null, 'sku' => 'REF-'.$i]);
        }
        $this->actingAs($admin)->getJson('/api/v1/inventory/items')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 22);
        $this->getJson('/api/v1/inventory/items?page=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inventory/items?search='.urlencode('%_supply'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/items?low_stock=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/items?search=ref-21')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/items?page=0')->assertUnprocessable();
    }

    public function test_foreign_keys_protect_ledger_context_and_deleted_actors_keep_uuid(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        $move = $this->move($admin, $item, $location);
        foreach ([$item, $item->balances()->sole(), StorageLocation::findOrFail($location['id'])] as $record) {
            try {
                DB::transaction(fn () => $record->delete());
                $this->fail('Referenced record deletion accepted');
            } catch (QueryException) {
                $this->assertTrue($record->newQuery()->whereKey($record->id)->exists());
            }
        }
        $admin->delete();
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->getJson('/api/v1/inventory/items/'.$item->id.'/movements')->assertJsonPath('data.0.actor.id', $admin->uuid)->assertJsonPath('data.0.actor.name', null);
    }

    public function test_migration_preserves_existing_accounts_locations_and_document_metadata(): void
    {
        $admin = $this->admin();
        $location = ['id' => Site::create(['name' => 'Preserved culture site'])->id];
        $file = UploadedFile::fake()->createWithContent('manual.txt', 'Test file');
        Storage::fake('documents');
        $document = app(DocumentLibrary::class)->upload($admin, ['title' => 'Preserved document'], $file);
        $hash = $admin->password;
        $this->reapplySchemaFrom('2026_11_06_100000_create_inventory.php');
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertDatabaseHas('sites', ['id' => $location['id']]);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        $this->assertDatabaseCount('inventory_items', 0);
    }

    public function test_movement_rate_limit_does_not_limit_reads_or_catalog_writes(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        $this->actingAs($admin);
        $url = '/api/v1/inventory/items/'.$item->id.'/movements';
        for ($i = 0; $i < 30; $i++) {
            $this->postJson($url, $this->input($item, $location, ['kind' => 'count', 'quantity' => '0']))->assertCreated();
        }
        $this->postJson($url, $this->input($item, $location))->assertTooManyRequests();
        $this->getJson('/api/v1/inventory/items')->assertOk();
        $this->postJson('/api/v1/inventory/items', ['name' => 'Other', 'unit' => 'piece'])->assertCreated();
    }

    public function test_audit_logs_omit_stock_quantities_reasons_names_and_skus(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        Log::spy();
        $movement = $this->move($admin, $item, $location);
        Log::shouldHaveReceived('notice')->with('inventory.movement_recorded', ['actor_uuid' => $admin->uuid, 'item_uuid' => $item->id, 'movement_uuid' => $movement->id, 'kind' => 'receipt', 'version' => 2])->once();
    }

    public function test_uuid_case_cannot_bypass_same_location_checks_or_retry_protection(): void
    {
        $admin = $this->admin();
        $item = $this->item($admin);
        $location = $this->location();
        $input = $this->input($item, $location);
        $url = '/api/v1/inventory/items/'.$item->id.'/movements';
        $this->actingAs($admin)->postJson($url, $input)->assertCreated();
        $replay = $input;
        $replay['request_id'] = strtoupper($input['request_id']);
        $replay['destination']['id'] = strtoupper($location['id']);
        $this->postJson($url, $replay)->assertCreated();
        $this->postJson($url, $this->input($item, $location, ['kind' => 'transfer', 'source' => ['id' => strtoupper($location['id'])], 'quantity' => '1']))
            ->assertUnprocessable()->assertJsonPath('errors.destination.0', 'inventory.validation.differentLocations');
        $this->assertSame(10125, $item->fresh()->total_units);
        $this->assertSame(10125, $item->balances()->sole()->quantity_units);
        $this->assertDatabaseCount('inventory_movements', 1);
    }
}
