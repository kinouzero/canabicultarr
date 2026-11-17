<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('creation_key')->unique();
            $table->string('creation_hash', 64);
            $table->foreignUuid('variety_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('name_key', 120)->index();
            $table->text('notes')->nullable();
            $table->string('phase', 24)->index();
            $table->timestampTz('started_at', 6);
            $table->timestampTz('phase_started_at', 6);
            $table->timestampTz('phase_due_at', 6)->nullable()->index();
            $table->json('duration_snapshot');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('zone_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampsTz(6);
        });
        Schema::create('plant_device', function (Blueprint $table) {
            $table->foreignUuid('plant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('asset_id')->constrained()->restrictOnDelete();
            $table->primary(['plant_id', 'asset_id']);
        });
        Schema::create('plant_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('plant_version');
            $table->unique(['plant_id', 'plant_version']);
            $table->uuid('request_id')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->uuid('actor_uuid')->nullable();
            $table->string('kind', 32);
            $table->string('from_phase', 24)->nullable();
            $table->string('to_phase', 24);
            $table->text('comment')->nullable();
            $table->json('snapshot');
            $table->json('fields');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6);
            $table->unique(['plant_id', 'request_id']);
            $table->index(['plant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_events');
        Schema::dropIfExists('plant_device');
        Schema::dropIfExists('plants');
    }
};
