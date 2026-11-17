<?php

namespace Tests\Feature;

use App\Exceptions\InventoryException;
use App\Models\InventoryItem;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\Inventory;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConcurrentStockMovementTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        DB::table('inventory_movements')->delete();
        DB::table('inventory_balances')->delete();
        DB::table('storage_locations')->delete();
        parent::tearDown();
    }

    public function test_competing_issues_have_one_winner_without_negative_stock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent row locking requires PostgreSQL.');
        }
        $admins = User::factory()->count(2)->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = StorageLocation::create(['name' => 'Warehouse']);
        $item = app(Inventory::class)->save($admins[0], ['name' => 'Supply', 'unit' => 'piece']);
        app(Inventory::class)->record($admins[0], $item, ['kind' => 'receipt', 'quantity' => '1', 'reason' => 'Opening receipt', 'destination' => ['id' => $site->id], 'version' => 1, 'request_id' => (string) Str::uuid()]);
        $itemId = $item->id;
        $siteId = $site->id;
        $database = DB::connection()->getDatabaseName();
        $operations = [];
        foreach ($admins as $admin) {
            $userId = $admin->id;
            $requestId = (string) Str::uuid();
            $operations[] = static function () use ($userId, $requestId, $itemId, $siteId, $database) {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database) {
                    return 'wrong_test_environment';
                }
                try {
                    app(Inventory::class)->record(User::findOrFail($userId), InventoryItem::findOrFail($itemId), ['kind' => 'issue', 'quantity' => '1', 'reason' => 'Competing withdrawal', 'source' => ['id' => $siteId], 'version' => 2, 'request_id' => $requestId]);

                    return 'recorded';
                } catch (InventoryException $exception) {
                    return $exception->errorCode;
                }
            };
        }
        $results = Concurrency::driver('process')->run($operations, timeout: 20);
        sort($results);
        $this->assertSame(['inventory.errors.conflict', 'recorded'], $results);
        $this->assertSame(0, $item->fresh()->total_units);
        $this->assertSame(0, $item->balances()->sole()->quantity_units);
        $this->assertSame(2, $item->movements()->count());
        $this->assertSame(3, $item->fresh()->version);
    }
}
