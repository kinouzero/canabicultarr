<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_assistant_devices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('instance_key', 64);
            $t->string('source_key', 255);
            $t->foreignUuid('asset_id')->unique()->constrained()->restrictOnDelete();
            $t->unique(['instance_key', 'source_key'], 'ha_device_source_unique');
            $t->timestamps();
        });
        Schema::create('home_assistant_links', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('instance_key', 64);
            $t->string('entity_id', 255);
            $t->foreignUuid('sensor_id')->unique()->constrained()->restrictOnDelete();
            $t->unique(['instance_key', 'entity_id'], 'ha_entity_unique');
            $t->boolean('is_enabled')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('last_attempt_at', 6)->nullable();
            $t->timestampTz('last_success_at', 6)->nullable();
            $t->string('last_error', 100)->nullable();
            $t->timestamps();
        });
        Schema::create('measurements', function (Blueprint $t) {
            $t->uuid('id');
            $t->timestampTz('observed_at', 6);
            $t->foreignUuid('sensor_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('home_assistant_link_id')->constrained()->restrictOnDelete();
            if (DB::getDriverName() === 'sqlite') {
                $t->text('value');
            } else {
                $t->decimal('value', 24, 9);
            }
            $t->string('unit', 24)->nullable();
            $t->json('location_snapshot')->nullable();
            $t->timestampTz('received_at', 6);
            $t->primary(['id', 'observed_at']);
            $t->unique(['sensor_id', 'observed_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT create_hypertable('measurements', by_range('observed_at', INTERVAL '7 days'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('measurements');
        Schema::dropIfExists('home_assistant_links');
        Schema::dropIfExists('home_assistant_devices');
    }
};
