<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mqtt_measurement_sources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('sensor_id')->unique()->constrained()->restrictOnDelete();
            $t->string('instance_key', 64);
            $t->string('topic', 200);
            $t->unique(['instance_key', 'topic']);
            $t->boolean('is_enabled')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('last_received_at', 6)->nullable();
            $t->timestampTz('last_attempt_at', 6)->nullable();
            $t->string('last_error', 100)->nullable();
            $t->timestamps();
        });
        Schema::table('measurements', fn (Blueprint $t) => $t->foreignUuid('mqtt_measurement_source_id')->nullable()->constrained()->restrictOnDelete());
    }

    public function down(): void
    {
        if (DB::table('measurements')->whereNotNull('mqtt_measurement_source_id')->exists()) {
            throw new RuntimeException('Retain MQTT measurements and restore their provenance from backup.');
        }
        Schema::table('measurements', fn (Blueprint $t) => $t->dropConstrainedForeignId('mqtt_measurement_source_id'));
        Schema::dropIfExists('mqtt_measurement_sources');
    }
};
