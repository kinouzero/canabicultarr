<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', fn (Blueprint $table) => $table->decimal('capacity_liters', 12, 3)->nullable());
        Schema::table('plants', fn (Blueprint $table) => $table->json('container_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('plants', fn (Blueprint $table) => $table->dropColumn('container_snapshot'));
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn('capacity_liters'));
    }
};
