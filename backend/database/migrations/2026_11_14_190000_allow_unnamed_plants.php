<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plants', fn (Blueprint $table) => $table->string('name', 120)->nullable()->change());
        DB::table('plants')->where('name', '')->update(['name' => null]);
    }

    public function down(): void
    {
        DB::table('plants')->whereNull('name')->update(['name' => '']);
        Schema::table('plants', fn (Blueprint $table) => $table->string('name', 120)->nullable(false)->change());
    }
};
