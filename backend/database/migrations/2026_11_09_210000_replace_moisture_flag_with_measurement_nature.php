<?php

use App\Enums\MeasurementNature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurement_types', fn (Blueprint $table) => $table->string('nature', 32)->default('other'));
        DB::table('measurement_types')->where('is_plant_moisture', true)->update(['nature' => 'substrate_moisture']);
        // Recognize only exact generated provider identities; do not guess from user labels.
        foreach (DB::table('measurement_types')->where('is_plant_moisture', false)->get(['id', 'code', 'unit']) as $type) {
            foreach (array_keys(MeasurementNature::DEVICE_CLASSES) as $class) {
                $hash = substr(hash('sha256', json_encode([$class, $type->unit])), 0, 32);
                if (in_array($type->code, ['ha_'.$hash, 'hb_'.$hash], true)) {
                    $nature = MeasurementNature::fromDeviceClass($class, $type->unit);
                    if ($nature !== MeasurementNature::SubstrateMoisture) {
                        DB::table('measurement_types')->where('id', $type->id)->update(['nature' => $nature->value]);
                    }
                    break;
                }
            }
        }
        Schema::table('measurement_types', fn (Blueprint $table) => $table->dropColumn('is_plant_moisture'));
    }

    public function down(): void
    {
        Schema::table('measurement_types', fn (Blueprint $table) => $table->boolean('is_plant_moisture')->default(false));
        DB::table('measurement_types')->where('nature', 'substrate_moisture')->update(['is_plant_moisture' => true]);
        Schema::table('measurement_types', fn (Blueprint $table) => $table->dropColumn('nature'));
    }
};
