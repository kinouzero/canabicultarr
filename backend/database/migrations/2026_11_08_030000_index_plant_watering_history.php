<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plant_events', fn (Blueprint $table) => $table->index(['plant_id', 'kind', 'occurred_at', 'plant_version'], 'plant_events_watering_lookup'));
    }

    public function down(): void
    {
        Schema::table('plant_events', fn (Blueprint $table) => $table->dropIndex('plant_events_watering_lookup'));
    }
};
