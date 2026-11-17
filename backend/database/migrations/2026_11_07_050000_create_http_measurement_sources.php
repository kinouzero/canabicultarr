<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('http_measurement_sources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('sensor_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('issuer_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('issuer_version');
            $t->string('token_hash', 64)->nullable();
            $t->timestampTz('expires_at');
            $t->boolean('is_enabled')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('last_received_at', 6)->nullable();
            $t->timestamps();
        });
        Schema::table('measurements', function (Blueprint $t) {
            $t->uuid('home_assistant_link_id')->nullable()->change();
            $t->foreignUuid('http_measurement_source_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('measurements')->whereNotNull('http_measurement_source_id')->exists()) {
            throw new RuntimeException('Retain HTTP measurements and restore from backup rather than dropping their provenance.');
        }
        Schema::table('measurements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('http_measurement_source_id');
            $t->uuid('home_assistant_link_id')->nullable(false)->change();
        });
        Schema::dropIfExists('http_measurement_sources');
    }
};
