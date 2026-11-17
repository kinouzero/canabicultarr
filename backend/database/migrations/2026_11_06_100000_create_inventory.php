<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('sku', 80)->nullable();
            $t->string('sku_key', 80)->nullable()->unique();
            $t->text('description')->nullable();
            $t->text('search_text');
            $t->string('unit', 16);
            $t->bigInteger('total_units')->default(0);
            $t->bigInteger('threshold_units')->nullable();
            $t->boolean('is_archived')->default(false)->index();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('unit_locked')->default(false);
            $t->timestamps();
        });
        Schema::create('inventory_balances', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('inventory_item_id')->constrained()->restrictOnDelete();
            $t->string('location_kind', 8);
            $t->uuid('location_uuid');
            $t->bigInteger('quantity_units')->default(0);
            foreach (['site' => 'sites', 'room' => 'rooms', 'zone' => 'zones'] as $n => $parent) {
                $t->foreignUuid($n.'_id')->nullable()->constrained($parent)->restrictOnDelete();
                $t->index($n.'_id');
            }
            $t->unique(['inventory_item_id', 'location_kind', 'location_uuid'], 'inventory_balance_location_unique');
            $t->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('inventory_item_id')->constrained()->restrictOnDelete();
            $t->uuid('request_id');
            $t->string('request_hash', 64);
            $t->unsignedInteger('version');
            $t->string('kind', 16);
            $t->bigInteger('quantity_units');
            $t->string('reason', 500);
            $t->string('item_name', 120);
            $t->string('unit', 16);
            foreach (['source', 'destination'] as $n) {
                $t->foreignUuid($n.'_balance_id')->nullable()->constrained('inventory_balances')->restrictOnDelete();
                $t->json($n.'_snapshot')->nullable();
                $t->bigInteger($n.'_before')->nullable();
                $t->bigInteger($n.'_after')->nullable();
            }
            $t->bigInteger('total_after');
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->uuid('actor_uuid');
            $t->timestampTz('recorded_at', 6);
            $t->unique(['inventory_item_id', 'request_id']);
            $t->unique(['inventory_item_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_items');
    }
};
