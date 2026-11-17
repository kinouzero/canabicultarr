<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_contexts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('asset_id')->constrained()->restrictOnDelete();
            $table->timestampTz('effective_at', 6);
            $table->char('fingerprint', 64);
            $table->json('snapshot');
            $table->index(['asset_id', 'effective_at', 'id']);
        });
        Schema::create('asset_context_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('asset_context_id')->constrained()->cascadeOnDelete();
            $table->uuid('plant_id')->nullable();
            // Historical IDs survive plant/location deletion; snapshots retain their labels.
            $table->uuid('site_id')->nullable();
            $table->uuid('room_id')->nullable();
            $table->uuid('zone_id')->nullable();
            $table->index(['asset_context_id', 'plant_id']);
        });
        Schema::table('measurements', function (Blueprint $table) {
            $table->foreignId('asset_context_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('provenance_snapshot')->nullable();
        });

    }

    public function down(): void
    {
        if (DB::table('measurements')->whereNotNull('provenance_snapshot')->exists()) {
            throw new RuntimeException('Retain recorded measurement provenance; restore a backup instead of dropping it.');
        }
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_context_id');
            $table->dropColumn('provenance_snapshot');
        });
        Schema::dropIfExists('asset_context_locations');
        Schema::dropIfExists('asset_contexts');
    }
};
