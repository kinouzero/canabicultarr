<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sites', 'rooms', 'zones'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedInteger('watering_alert_days')->nullable());
        }
        Schema::table('lifecycle_settings', function (Blueprint $t) {
            $t->unsignedInteger('watering_alert_days')->default(3);
            $t->decimal('watering_moisture_threshold', 5, 2)->default(35);
        });
        Schema::table('measurement_types', fn (Blueprint $t) => $t->boolean('is_plant_moisture')->default(false));
        // Home Assistant moisture is substrate moisture; humidity is ambient air.
        $code = 'ha_'.substr(hash('sha256', json_encode(['moisture', '%'])), 0, 32);
        DB::table('measurement_types')->where('code', $code)->where('unit', '%')->update(['is_plant_moisture' => true]);
    }

    public function down(): void
    {
        Schema::table('measurement_types', fn (Blueprint $t) => $t->dropColumn('is_plant_moisture'));
        Schema::table('lifecycle_settings', fn (Blueprint $t) => $t->dropColumn(['watering_alert_days', 'watering_moisture_threshold']));
        foreach (['sites', 'rooms', 'zones'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('watering_alert_days'));
        }
    }
};
