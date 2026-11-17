<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plants', fn (Blueprint $table) => $table->json('summary_sensor_ids')->nullable());
    }

    public function down(): void
    {
        Schema::table('plants', fn (Blueprint $table) => $table->dropColumn('summary_sensor_ids'));
    }
};
