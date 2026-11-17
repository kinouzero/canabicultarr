<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_settings', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->text('values')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        foreach (['storage', 'mail', 'home_assistant', 'homebridge', 'mqtt', 'oidc', 'webhook', 'push'] as $group) {
            DB::table('connection_settings')->insert(['id' => $group, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_settings');
    }
};
