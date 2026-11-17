<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetContext;
use App\Models\Site;
use App\Models\User;
use App\Services\AssetAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetLocationMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        DB::table('asset_assignments')->delete();
        DB::table('asset_locations')->delete();
        parent::tearDown();
    }

    public function test_existing_assignments_contexts_identifiers_and_users_survive_migration_and_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_15_110000_add_multiple_asset_locations.php');
        $migration->down();
        $site = Site::create(['name' => 'Preserved original site']);
        $id = (string) Str::uuid();
        $user = User::factory()->create();
        $userAttributes = $user->fresh()->getAttributes();
        DB::table('assets')->insert(['id' => $id, 'name' => 'Existing equipment', 'name_key' => 'existing equipment', 'status' => 'active', 'category' => 'sensor', 'site_id' => $site->id, 'created_at' => now(), 'updated_at' => now()]);
        $location = ['type' => 'site', 'id' => $site->id, 'site' => ['id' => $site->id, 'name' => $site->name], 'room' => null, 'zone' => null];
        $old = AssetContext::create(['asset_id' => $id, 'effective_at' => now()->subHour(), 'fingerprint' => hash('sha256', 'old-format'), 'snapshot' => ['asset' => ['id' => $id, 'name' => 'Existing equipment'], 'location' => $location, 'plants' => []]]);
        $snapshot = $old->getRawOriginal('snapshot');
        $migration->up();
        $this->assertDatabaseCount('asset_locations', 1);
        $this->assertDatabaseCount('asset_contexts', 1);
        $this->assertSame($snapshot, $old->fresh()->getRawOriginal('snapshot'));
        $this->assertSame($id, Asset::sole()->id);
        $this->assertSame($site->id, Asset::sole()->locationList()[0]['id']);
        $this->assertSame($userAttributes, $user->fresh()->getAttributes());
        $migration->down();
        $this->assertDatabaseHas('assets', ['id' => $id, 'site_id' => $site->id]);
        $migration->up();
        $this->assertSame($snapshot, $old->fresh()->getRawOriginal('snapshot'));
    }

    public function test_rollback_cannot_silently_discard_a_multiple_assignment_or_its_history(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $first = Site::create(['name' => 'First placement']);
        $second = Site::create(['name' => 'Second placement']);
        app(AssetAdministration::class)->save($admin, ['name' => 'Shared', 'locations' => [['type' => 'site', 'id' => $first->id], ['type' => 'site', 'id' => $second->id]]]);
        $migration = require database_path('migrations/2026_11_15_110000_add_multiple_asset_locations.php');
        try {
            $migration->down();
            $this->fail('Multiple assignments must not be collapsed.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Retain multiple assignment history', $error->getMessage());
        }
        $this->assertDatabaseCount('asset_locations', 2);
        $this->assertDatabaseCount('asset_assignments', 1);
    }

    public function test_database_constraints_reject_empty_mixed_and_duplicate_assignment_rows(): void
    {
        $asset = Asset::create(['name' => 'Constraint fixture']);
        $site = Site::create(['name' => 'Constraint site']);
        $room = $site->rooms()->create(['name' => 'Constraint room']);
        $base = ['id' => (string) Str::uuid(), 'asset_id' => $asset->id, 'site_id' => null, 'room_id' => null, 'zone_id' => null];
        foreach ([$base, [...$base, 'site_id' => $site->id, 'room_id' => $room->id]] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('asset_locations')->insert($invalid));
                $this->fail('Invalid assignment row accepted.');
            } catch (QueryException) {
                $this->assertDatabaseCount('asset_locations', 0);
            }
        }
        DB::table('asset_locations')->insert([...$base, 'site_id' => $site->id]);
        try {
            DB::transaction(fn () => DB::table('asset_locations')->insert([...$base, 'id' => (string) Str::uuid(), 'site_id' => $site->id]));
            $this->fail('Duplicate assignment accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('asset_locations', 1);
        }
    }
}
