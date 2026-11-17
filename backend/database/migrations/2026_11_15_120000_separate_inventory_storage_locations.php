<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 240);
            $table->text('description')->nullable();
            $table->boolean('is_archived')->default(false)->index();
            $table->json('migration_origin')->nullable();
            $table->timestamps();
        });
        Schema::table('users', fn (Blueprint $table) => $table->json('inventory_location_access')->nullable());
        Schema::table('inventory_balances', function (Blueprint $table) {
            $table->foreignUuid('storage_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('storage_location_id');
        });
        $origins = [];
        DB::table('inventory_balances')->orderBy('id')->chunkById(100, function ($balances) use (&$origins) {
            foreach ($balances as $balance) {
                $key = $balance->location_kind.':'.$balance->location_uuid;
                if (! isset($origins[$key])) {
                    $site = $balance->site_id ? DB::table('sites')->where('id', $balance->site_id)->value('name') : null;
                    $room = $balance->room_id ? DB::table('rooms')->where('id', $balance->room_id)->value('name') : null;
                    $zone = $balance->zone_id ? DB::table('zones')->where('id', $balance->zone_id)->value('name') : null;
                    $origin = ['kind' => $balance->location_kind, 'id' => $balance->location_uuid, 'site_id' => $balance->site_id, 'room_id' => $balance->room_id, 'zone_id' => $balance->zone_id];
                    $id = (string) Str::uuid();
                    DB::table('storage_locations')->insert(['id' => $id, 'name' => mb_substr(implode(' / ', array_filter([$site, $room, $zone])), 0, 240), 'migration_origin' => json_encode($origin, JSON_THROW_ON_ERROR), 'is_archived' => false, 'created_at' => now(), 'updated_at' => now()]);
                    $origins[$key] = [...$origin, 'id' => $id];
                }
                DB::table('inventory_balances')->where('id', $balance->id)->update(['storage_location_id' => $origins[$key]['id']]);
            }
        });
        DB::table('users')->whereNotNull('location_access')->orderBy('id')->chunkById(100, function ($users) use ($origins) {
            foreach ($users as $user) {
                $grants = json_decode($user->location_access, true, 512, JSON_THROW_ON_ERROR);
                $ids = [];
                foreach ($origins as $origin) {
                    foreach ($grants as $grant) {
                        if (($origin[$grant['type'].'_id'] ?? null) === $grant['id']) {
                            $ids[] = $origin['id'];
                            break;
                        }
                    }
                }
                DB::table('users')->where('id', $user->id)->update(['inventory_location_access' => json_encode($ids, JSON_THROW_ON_ERROR)]);
            }
        });
        Schema::withoutForeignKeyConstraints(fn () => Schema::table('inventory_balances', function (Blueprint $table) {
            $table->dropUnique('inventory_balance_location_unique');
            foreach (['site', 'room', 'zone'] as $kind) {
                $table->dropIndex([$kind.'_id']);
                $table->dropConstrainedForeignId($kind.'_id');
            }
            $table->dropColumn(['location_kind', 'location_uuid']);
            $table->foreignUuid('storage_location_id')->nullable(false)->change();
            $table->unique(['inventory_item_id', 'storage_location_id']);
        }));
    }

    public function down(): void
    {
        if (DB::table('storage_locations')->whereNull('migration_origin')->exists()
            || DB::table('inventory_movements')->whereNotNull('source_snapshot->name')->orWhereNotNull('destination_snapshot->name')->exists()) {
            throw new RuntimeException('Retain independent storage locations and new movement history; restore a pre-migration backup for a full downgrade.');
        }
        $locations = DB::table('storage_locations')->get()->keyBy('id');
        foreach ($locations as $location) {
            $origin = json_decode($location->migration_origin, true, 512, JSON_THROW_ON_ERROR);
            foreach (['site', 'room', 'zone'] as $kind) {
                if ($origin[$kind.'_id'] && ! DB::table($kind === 'site' ? 'sites' : $kind.'s')->where('id', $origin[$kind.'_id'])->exists()) {
                    throw new RuntimeException('Original cultivation location was removed; restore a pre-migration backup.');
                }
            }
        }
        Schema::withoutForeignKeyConstraints(fn () => Schema::table('inventory_balances', function (Blueprint $table) {
            $table->string('location_kind', 8)->nullable();
            $table->uuid('location_uuid')->nullable();
            foreach (['site', 'room', 'zone'] as $kind) {
                $table->foreignUuid($kind.'_id')->nullable()->constrained($kind === 'site' ? 'sites' : $kind.'s')->restrictOnDelete();
                $table->index($kind.'_id');
            }
        }));
        foreach ($locations as $location) {
            $origin = json_decode($location->migration_origin, true, 512, JSON_THROW_ON_ERROR);
            DB::table('inventory_balances')->where('storage_location_id', $location->id)->update(['location_kind' => $origin['kind'], 'location_uuid' => $origin['id'], ...array_intersect_key($origin, array_flip(['site_id', 'room_id', 'zone_id']))]);
        }
        Schema::withoutForeignKeyConstraints(fn () => Schema::table('inventory_balances', function (Blueprint $table) {
            $table->dropUnique(['inventory_item_id', 'storage_location_id']);
            $table->dropIndex(['storage_location_id']);
            $table->dropConstrainedForeignId('storage_location_id');
            $table->string('location_kind', 8)->nullable(false)->change();
            $table->uuid('location_uuid')->nullable(false)->change();
            $table->unique(['inventory_item_id', 'location_kind', 'location_uuid'], 'inventory_balance_location_unique');
        }));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('inventory_location_access'));
        Schema::dropIfExists('storage_locations');
    }
};
