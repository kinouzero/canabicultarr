<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifecycle_settings', function (Blueprint $table) {
            $table->boolean('automatic_relocation')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('lifecycle_settings', function (Blueprint $table) {
            $table->dropColumn('automatic_relocation');
        });
    }
};
