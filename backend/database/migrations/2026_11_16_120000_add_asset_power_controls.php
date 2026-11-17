<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->json('power_control')->nullable());
        DB::statement('CREATE INDEX assets_power_controlled ON assets (id) WHERE power_control IS NOT NULL');
        Schema::create('device_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained('assets')->restrictOnDelete();
            $table->uuid('actor_uuid');
            $table->string('schedule_key', 64)->nullable()->unique();
            $table->boolean('is_on');
            $table->string('status', 16);
            $table->json('control');
            $table->timestampTz('created_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->index(['asset_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('device_commands')->exists()) {
            throw new RuntimeException('Refusing to discard device command history.');
        }
        Schema::dropIfExists('device_commands');
        DB::statement('DROP INDEX assets_power_controlled');
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('power_control'));
    }
};
