<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurements', fn (Blueprint $table) => $table->string('quality', 12)->default('unknown'));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE measurements ADD CONSTRAINT measurements_quality_values CHECK (quality IN ('unknown','good','suspect','invalid'))");
        } else {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $name = strtolower($operation);
                DB::statement("CREATE TRIGGER measurements_quality_$name BEFORE $operation ON measurements WHEN NEW.quality NOT IN ('unknown','good','suspect','invalid') OR NEW.quality IS NULL BEGIN SELECT RAISE(ABORT, 'A supported measurement quality is required'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER measurements_quality_insert');
            DB::statement('DROP TRIGGER measurements_quality_update');
        }
        Schema::table('measurements', fn (Blueprint $table) => $table->dropColumn('quality'));
    }
};
