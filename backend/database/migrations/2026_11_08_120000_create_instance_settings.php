<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('date_format', 16)->default('d/m/Y');
            $table->string('time_format', 2)->default('24');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        DB::table('instance_settings')->insert(['id' => 1, 'date_format' => 'd/m/Y', 'time_format' => '24', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_settings');
    }
};
