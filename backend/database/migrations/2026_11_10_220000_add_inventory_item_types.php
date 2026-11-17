<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('type', 32)->default('other')->index();
        });
        DB::table('inventory_items')->whereIn('id', DB::table('varieties')->select('seed_inventory_item_id')->whereNotNull('seed_inventory_item_id'))->update(['type' => 'seeds']);
    }

    public function down(): void
    {
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropIndex(['type']));
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn('type'));
    }
};
