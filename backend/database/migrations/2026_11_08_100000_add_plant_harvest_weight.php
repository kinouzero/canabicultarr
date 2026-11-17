<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            $table->decimal('harvest_weight_grams', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plants', fn (Blueprint $table) => $table->dropColumn('harvest_weight_grams'));
    }
};
