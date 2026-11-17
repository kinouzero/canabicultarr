<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plant_maintenance_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('name_key', 80)->unique();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        $names = DB::table('plant_events')->where('kind', 'maintained')->select('snapshot->maintenance->type as label')->distinct()->pluck('label');
        foreach ($names as $name) {
            if (is_string($name) && filled($name) && ! in_array($name, ['topping', 'pruning', 'other'], true)) {
                DB::table('plant_maintenance_types')->insertOrIgnore(['id' => (string) Str::uuid(), 'name' => $name, 'name_key' => Str::lower($name), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_maintenance_types');
    }
};
