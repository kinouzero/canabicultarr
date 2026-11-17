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
            $table->unsignedTinyInteger('decimal_places')->default(3);
        });
        DB::table('inventory_items')->where('unit', 'piece')->update(['decimal_places' => 0]);
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('decimal_places');
        });
    }
};
