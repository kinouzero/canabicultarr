<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homebridge_devices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('instance_key', 64);
            $t->string('source_key', 255);
            $t->foreignUuid('asset_id')->unique()->constrained()->restrictOnDelete();
            $t->unique(['instance_key', 'source_key']);
            $t->timestamps();
        });
        Schema::create('homebridge_links', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('instance_key', 64);
            $t->string('entity_id', 255);
            $t->foreignUuid('sensor_id')->unique()->constrained()->restrictOnDelete();
            $t->unique(['instance_key', 'entity_id']);
            $t->boolean('is_enabled')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('last_attempt_at', 6)->nullable();
            $t->timestampTz('last_success_at', 6)->nullable();
            $t->string('last_error', 100)->nullable();
            $t->timestamps();
        });
        Schema::table('measurements', function (Blueprint $t) {
            $t->foreignUuid('homebridge_link_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('measurements', fn (Blueprint $t) => $t->dropConstrainedForeignId('homebridge_link_id'));
        Schema::dropIfExists('homebridge_links');
        Schema::dropIfExists('homebridge_devices');
    }
};
