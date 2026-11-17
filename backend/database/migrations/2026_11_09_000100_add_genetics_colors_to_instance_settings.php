<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->json('genetics_colors')->default(json_encode(['sativa' => '#c75b50', 'indica' => '#527eae', 'hybrid' => '#b9922e']));
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('genetics_colors');
        });
    }
};
