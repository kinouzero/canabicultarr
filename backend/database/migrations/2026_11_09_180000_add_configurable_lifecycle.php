<?php

use App\Services\LifecycleDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifecycle_settings', fn (Blueprint $table) => $table->json('stages')->nullable());
        DB::table('lifecycle_settings')->where('id', 1)->update(['stages' => json_encode(LifecycleDefinition::defaults(), JSON_THROW_ON_ERROR)]);
    }

    public function down(): void
    {
        Schema::table('lifecycle_settings', fn (Blueprint $table) => $table->dropColumn('stages'));
    }
};
