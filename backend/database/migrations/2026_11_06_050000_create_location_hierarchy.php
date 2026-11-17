<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sites', 'rooms', 'zones'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->uuid('id')->primary();
                if ($name === 'rooms') {
                    $table->foreignUuid('site_id')->constrained()->restrictOnDelete();
                } elseif ($name === 'zones') {
                    $table->foreignUuid('room_id')->constrained()->restrictOnDelete();
                }
                $table->string('name', 120);
                $table->string('name_key', 120);
                $table->text('description')->nullable();
                $table->timestamps();
                $table->unique(match ($name) {
                    'rooms' => ['site_id', 'name_key'],
                    'zones' => ['room_id', 'name_key'],
                    default => ['name_key'],
                });
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('sites');
    }
};
