<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['home_assistant_links', 'homebridge_links'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('control_entity', 255)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['home_assistant_links', 'homebridge_links'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('control_entity'));
        }
    }
};
