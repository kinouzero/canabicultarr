<?php

use App\Models\Asset;
use App\Services\AssetContexts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('zone_id')->nullable()->constrained()->restrictOnDelete();
            foreach (['site', 'room', 'zone'] as $kind) {
                $table->unique(['asset_id', $kind.'_id']);
            }
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE asset_locations ADD CONSTRAINT asset_locations_one_node CHECK (num_nonnulls(site_id,room_id,zone_id) = 1)');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $name = strtolower($operation);
                DB::statement("CREATE TRIGGER asset_locations_one_node_$name BEFORE $operation ON asset_locations WHEN ((NEW.site_id IS NOT NULL) + (NEW.room_id IS NOT NULL) + (NEW.zone_id IS NOT NULL)) <> 1 BEGIN SELECT RAISE(ABORT, 'Exactly one location node is required'); END");
            }
        }
        DB::table('assets')->orderBy('id')->chunkById(100, function ($assets) {
            $rows = [];
            foreach ($assets as $asset) {
                if ($asset->site_id || $asset->room_id || $asset->zone_id) {
                    $rows[] = ['id' => (string) Str::uuid(), 'asset_id' => $asset->id, 'site_id' => $asset->site_id, 'room_id' => $asset->room_id, 'zone_id' => $asset->zone_id];
                }
            }
            if ($rows) {
                DB::table('asset_locations')->insert($rows);
            }
        });
        Schema::withoutForeignKeyConstraints(fn () => Schema::table('assets', function (Blueprint $table) {
            foreach (['site', 'room', 'zone'] as $kind) {
                $table->dropConstrainedForeignId($kind.'_id');
            }
        }));
        Schema::table('asset_assignments', function (Blueprint $table) {
            $table->json('locations_snapshot')->nullable();
            $table->foreignId('asset_context_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('asset_assignment_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('asset_assignment_id')->constrained()->cascadeOnDelete();
            $table->index('asset_assignment_id');
            foreach (['site', 'room', 'zone'] as $kind) {
                $table->foreignUuid($kind.'_id')->nullable()->index()->constrained($kind === 'site' ? 'sites' : $kind.'s')->restrictOnDelete();
            }
        });
        Schema::table('plant_device', fn (Blueprint $table) => $table->index('asset_id', 'plant_device_asset_lookup'));
        DB::statement(<<<'SQL'
            CREATE VIEW asset_boundaries AS
            SELECT l.asset_id, ('location:' || CAST(l.id AS VARCHAR)) AS boundary_id, CAST(NULL AS UUID) AS plant_id,
                COALESCE(l.site_id,r.site_id,zr.site_id) AS site_id,
                COALESCE(l.room_id,z.room_id) AS room_id,l.zone_id
            FROM asset_locations l LEFT JOIN rooms r ON r.id=l.room_id
                LEFT JOIN zones z ON z.id=l.zone_id LEFT JOIN rooms zr ON zr.id=z.room_id
            UNION ALL
            SELECT d.asset_id,('plant:' || CAST(p.id AS VARCHAR)) AS boundary_id,p.id AS plant_id,COALESCE(p.site_id,r.site_id,zr.site_id) AS site_id,
                COALESCE(p.room_id,z.room_id) AS room_id,p.zone_id
            FROM plant_device d JOIN plants p ON p.id=d.plant_id
                LEFT JOIN rooms r ON r.id=p.room_id LEFT JOIN zones z ON z.id=p.zone_id
                LEFT JOIN rooms zr ON zr.id=z.room_id
SQL);
        Asset::withoutGlobalScopes()->orderBy('id')->chunkById(100, function ($assets) {
            foreach ($assets as $asset) {
                app(AssetContexts::class)->capture($asset->id);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('asset_locations')->select('asset_id')->groupBy('asset_id')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('asset_assignments')->whereNotNull('locations_snapshot')->exists()) {
            throw new RuntimeException('Retain multiple assignment history; restore a backup rather than collapsing it.');
        }
        DB::statement('DROP VIEW asset_boundaries');
        Schema::dropIfExists('asset_assignment_locations');
        Schema::table('plant_device', fn (Blueprint $table) => $table->dropIndex('plant_device_asset_lookup'));
        Schema::withoutForeignKeyConstraints(fn () => Schema::table('assets', function (Blueprint $table) {
            foreach (['site', 'room', 'zone'] as $kind) {
                $table->foreignUuid($kind.'_id')->nullable()->constrained($kind === 'site' ? 'sites' : $kind.'s')->restrictOnDelete();
            }
        }));
        DB::table('asset_locations')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('assets')->where('id', $row->asset_id)->update(['site_id' => $row->site_id, 'room_id' => $row->room_id, 'zone_id' => $row->zone_id]);
            }
        });
        Schema::table('asset_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_context_id');
            $table->dropColumn('locations_snapshot');
        });
        Schema::dropIfExists('asset_locations');
    }
};
