<?php

use App\Support\ThemePalette;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', fn (Blueprint $table) => $table->json('theme')->default(json_encode(ThemePalette::defaults())));
    }

    public function down(): void
    {
        Schema::table('instance_settings', fn (Blueprint $table) => $table->dropColumn('theme'));
    }
};
