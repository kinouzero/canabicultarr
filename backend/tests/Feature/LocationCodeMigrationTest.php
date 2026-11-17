<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationCodeMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_backfill_and_round_trip_preserve_names_ids_parent_links_and_timestamps(): void
    {
        $migration = require database_path('migrations/2026_11_15_150000_add_room_zone_codes.php');
        $migration->down();
        $site = Site::create(['name' => 'Existing site']);
        $roomId = (string) Str::uuid();
        $zoneId = (string) Str::uuid();
        DB::table('rooms')->insert(['id' => $roomId, 'site_id' => $site->id, 'name' => 'Existing room', 'name_key' => 'existing room', 'description' => 'Original notes', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('zones')->insert(['id' => $zoneId, 'room_id' => $roomId, 'name' => 'Existing zone', 'name_key' => 'existing zone', 'created_at' => now(), 'updated_at' => now()]);
        $before = [(array) DB::table('rooms')->sole(), (array) DB::table('zones')->sole()];
        $migration->up();
        $this->assertSame('ROOM-001', DB::table('rooms')->sole()->code);
        $this->assertSame('ZONE-001', DB::table('zones')->sole()->code);
        $this->assertSame(0, DB::table('asset_boundaries')->count());
        $migration->down();
        $this->assertSame($before, [(array) DB::table('rooms')->sole(), (array) DB::table('zones')->sole()]);
        $migration->up();
        $this->assertSame('ROOM-001', DB::table('rooms')->sole()->code);
        $this->assertSame($roomId, DB::table('zones')->sole()->room_id);
    }
}
