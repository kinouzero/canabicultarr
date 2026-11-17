<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->json('durations');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        DB::table('lifecycle_settings')->insert(['id' => 1, 'durations' => json_encode([
            'sowing' => 3, 'germination' => 7, 'growth' => 21, 'flowering' => 56,
            'hanging_drying' => 7, 'rack_drying' => 7, 'curing' => 14, 'flush' => 14,
        ], JSON_THROW_ON_ERROR), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('varieties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('name_key', 120)->unique();
            $table->string('source_url', 2000)->nullable();
            $table->string('classification', 16)->nullable();
            $table->text('genetics')->nullable();
            $table->text('lineage')->nullable();
            $table->string('thc', 120)->nullable();
            $table->string('cbd', 120)->nullable();
            $table->text('aroma')->nullable();
            $table->text('reported_effects')->nullable();
            $table->text('notes')->nullable();
            $table->json('duration_overrides')->nullable();
            $table->boolean('is_archived')->default(false)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('varieties');
        Schema::dropIfExists('lifecycle_settings');
    }
};
