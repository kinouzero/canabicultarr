<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 120);
            $table->string('name_key', 120)->index();
            $table->string('unit', 24)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('sensors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('measurement_type_id')->constrained()->restrictOnDelete();
            $table->index('measurement_type_id');
            $table->string('channel_key', 64);
            $table->string('name', 120);
            $table->string('name_key', 120)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->unsignedInteger('expected_interval_seconds')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'channel_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensors');
        Schema::dropIfExists('measurement_types');
    }
};
