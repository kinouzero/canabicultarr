<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('lifecycle_settings')->get() as $setting) {
            $stages = json_decode($setting->stages, true, 512, JSON_THROW_ON_ERROR);
            foreach ($stages as &$stage) {
                $products = $stage['fertilizers'] ?? [];
                unset($stage['fertilizers']);
                $stage['fertilizer_periods'] = $products ? [['id' => (string) Str::uuid(), 'name' => null, 'unit' => 'days', 'start' => 0, 'end' => null, 'products' => $products]] : [];
            }
            unset($stage);
            DB::table('lifecycle_settings')->where('id', $setting->id)->update(['stages' => json_encode($stages, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('lifecycle_settings')->get() as $setting) {
            $stages = json_decode($setting->stages, true, 512, JSON_THROW_ON_ERROR);
            foreach ($stages as &$stage) {
                $periods = $stage['fertilizer_periods'] ?? [];
                if (count($periods) > 1 || ($periods && ($periods[0]['unit'] !== 'days' || $periods[0]['start'] !== 0 || $periods[0]['end'] !== null || $periods[0]['name'] !== null))) {
                    throw new RuntimeException('Retain timed fertilizer configuration; restore a pre-migration backup for a full downgrade.');
                }
                unset($stage['fertilizer_periods']);
                if ($periods) {
                    $stage['fertilizers'] = $periods[0]['products'];
                }
            }
            unset($stage);
            DB::table('lifecycle_settings')->where('id', $setting->id)->update(['stages' => json_encode($stages, JSON_THROW_ON_ERROR)]);
        }
    }
};
