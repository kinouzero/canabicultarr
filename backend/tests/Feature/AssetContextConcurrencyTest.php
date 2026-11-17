<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetContext;
use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetContextConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_hardware_changes_preserve_each_committed_revision(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL row locks.');
        }
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Original']);
        $userId = $admin->id;
        $assetId = $asset->id;
        $database = DB::connection()->getDatabaseName();
        $jobs = [];
        foreach (['First revision', 'Second revision'] as $name) {
            $jobs[] = static function () use ($userId, $assetId, $database, $name) {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database || ! str_contains($database, '_test')) {
                    throw new \RuntimeException('Isolated test database required.');
                }
                app(AssetAdministration::class)->save(User::findOrFail($userId), ['name' => $name], Asset::findOrFail($assetId));

                return 'saved';
            };
        }
        $this->assertSame(['saved', 'saved'], Concurrency::driver('process')->run($jobs, timeout: 20));
        $this->assertDatabaseCount('asset_contexts', 3);
        $this->assertEqualsCanonicalizing(['Original', 'First revision', 'Second revision'], AssetContext::orderBy('id')->get()->map(fn ($revision) => $revision->snapshot['asset']['name'])->all());
    }

    public function test_concurrent_location_replacements_remain_complete_and_keep_both_revisions(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL row locks.');
        }
        $profile = AccessProfileSeeder::defaultProfile('administrator')->id;
        $users = [User::factory()->create(['permission_role_ids' => [$profile]]), User::factory()->create(['permission_role_ids' => [$profile]])];
        $asset = app(AssetAdministration::class)->save($users[0], ['name' => 'Concurrent shared hardware']);
        $sets = [];
        foreach (['First', 'Second'] as $name) {
            $sets[] = array_map(fn ($suffix) => ['type' => 'site', 'id' => Site::create(['name' => $name.' '.$suffix])->id], ['A', 'B']);
        }
        $assetId = $asset->id;
        $database = DB::connection()->getDatabaseName();
        $jobs = [];
        foreach ($sets as $index => $locations) {
            $userId = $users[$index]->id;
            $jobs[] = static function () use ($userId, $assetId, $database, $locations) {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database || ! str_contains($database, '_test')) {
                    throw new \RuntimeException('Isolated test database required.');
                }
                app(AssetAdministration::class)->save(User::findOrFail($userId), ['locations' => $locations], Asset::findOrFail($assetId));

                return 'saved';
            };
        }
        try {
            $this->assertSame(['saved', 'saved'], Concurrency::driver('process')->run($jobs, timeout: 20));
            $expected = array_map(fn ($locations) => collect($locations)->pluck('id')->sort()->values()->all(), $sets);
            $history = $asset->assignments()->get()->map(fn ($assignment) => collect($assignment->locations_snapshot)->pluck('id')->sort()->values()->all())->all();
            $this->assertEqualsCanonicalizing($expected, $history);
            $current = collect($asset->fresh()->locationList())->pluck('id')->sort()->values()->all();
            $this->assertContains($current, $expected);
            $this->assertDatabaseCount('asset_contexts', 3);
            $this->assertDatabaseCount('asset_locations', 2);
        } finally {
            DB::table('asset_assignments')->delete();
            DB::table('asset_locations')->delete();
        }
    }

    public function test_two_plants_can_exchange_hardware_without_inverting_asset_locks(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL row locks.');
        }
        $profile = AccessProfileSeeder::defaultProfile('administrator')->id;
        $users = [User::factory()->create(['permission_role_ids' => [$profile]]), User::factory()->create(['permission_role_ids' => [$profile]])];
        $site = Site::create(['name' => 'Swap site']);
        $strain = Strain::create(['name' => 'Swap strain']);
        $assets = [app(AssetAdministration::class)->save($users[0], ['name' => 'First hardware']), app(AssetAdministration::class)->save($users[0], ['name' => 'Second hardware'])];
        $plants = [];
        foreach ($assets as $i => $asset) {
            $plants[] = app(PlantTracking::class)->create($users[$i], ['name' => 'Plant '.$i, 'request_id' => (string) Str::uuid(), 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id], 'device_ids' => [$asset->id]]);
        }
        $directory = sys_get_temp_dir().'/assignment-swap-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = DB::connection()->getDatabaseName();
        $jobs = [];
        foreach ($plants as $i => $plant) {
            $userId = $users[$i]->id;
            $plantId = $plant->id;
            $assetId = $assets[1 - $i]->id;
            $jobs[] = static function () use ($userId, $plantId, $assetId, $directory, $database, $i) {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database || ! str_contains($database, '_test')) {
                    throw new \RuntimeException('Isolated test database required.');
                }
                touch($directory.'/'.$i);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new \RuntimeException('Assignment race barrier timeout.');
                    }
                    usleep(10000);
                }
                app(PlantTracking::class)->update(User::findOrFail($userId), Plant::findOrFail($plantId), ['version' => 1, 'device_ids' => [$assetId]]);

                return 'swapped';
            };
        }
        try {
            $this->assertSame(['swapped', 'swapped'], Concurrency::driver('process')->run($jobs, timeout: 20));
            foreach ($plants as $i => $plant) {
                $this->assertSame([$assets[1 - $i]->id], $plant->devices()->pluck('assets.id')->all());
                $latest = AssetContext::where('asset_id', $assets[1 - $i]->id)->orderByDesc('effective_at')->orderByDesc('id')->firstOrFail();
                $this->assertSame([$plant->id], array_column($latest->snapshot['plants'], 'id'));
            }
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            DB::table('plant_events')->delete();
            DB::table('plant_device')->delete();
            DB::table('plants')->delete();
        }
    }
}
