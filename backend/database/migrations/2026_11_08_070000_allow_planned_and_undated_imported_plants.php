<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            $table->timestampTz('started_at', 6)->nullable()->change();
            $table->timestampTz('phase_started_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            $table->timestampTz('started_at', 6)->nullable(false)->change();
            $table->timestampTz('phase_started_at', 6)->nullable(false)->change();
        });
    }
};
