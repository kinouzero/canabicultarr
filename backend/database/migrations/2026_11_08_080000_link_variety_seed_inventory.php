<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('varieties', function (Blueprint $table) {
            $table->foreignUuid('seed_inventory_item_id')->nullable()->unique()->constrained('inventory_items')->nullOnDelete();
        });
        // The authorized Trello import stored an exact variety UUID in seed stock descriptions.
        DB::table('inventory_items')->where('unit', 'piece')->where('sku', 'like', 'trello-%')->orderBy('id')->chunk(100, function ($items) {
            foreach ($items as $item) {
                if (preg_match('/^Seed stock imported as an opening count, without replaying historical seed consumption\. Variety: ([0-9a-f-]{36})\n/', $item->description ?? '', $match)) {
                    DB::table('varieties')->where('id', $match[1])->whereNull('seed_inventory_item_id')->update(['seed_inventory_item_id' => $item->id]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('varieties', function (Blueprint $table) {
            $table->dropForeign(['seed_inventory_item_id']);
            $table->dropUnique(['seed_inventory_item_id']);
            $table->dropColumn('seed_inventory_item_id');
        });
    }
};
