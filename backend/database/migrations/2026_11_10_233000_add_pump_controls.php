<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->json('pump_control')->nullable());
        Schema::create('pump_activations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('plant_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('actor_uuid');
            $table->string('status', 16);
            $table->json('control');
            $table->timestampTz('created_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('stop_due_at')->nullable()->index();
            $table->timestampTz('stopped_at')->nullable();
            $table->string('stop_status', 16)->nullable();
            $table->index(['asset_id', 'plant_id', 'actor_uuid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pump_activations');
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('pump_control'));
    }
};
