<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 20)->index();
            $table->string('name', 120);
            $table->string('name_key', 120)->index();
            $table->string('status', 20)->default('active')->index();
            $table->string('asset_tag', 80)->nullable();
            $table->string('asset_tag_key', 80)->nullable()->unique();
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->text('description')->nullable();
            $table->foreignUuid('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('zone_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('zone_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('assigned_at');
            $table->index(['asset_id', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
    }
};
