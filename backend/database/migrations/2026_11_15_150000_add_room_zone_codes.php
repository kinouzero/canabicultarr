<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['rooms' => 'site_id', 'zones' => 'room_id'] as $table => $parent) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('code', 32)->nullable());
            $prefix = $table === 'rooms' ? 'ROOM-' : 'ZONE-';
            $previous = null;
            $number = 0;
            foreach (DB::table($table)->select('id', $parent)->orderBy($parent)->orderBy('id')->cursor() as $record) {
                if ($previous !== $record->$parent) {
                    $previous = $record->$parent;
                    $number = 0;
                }
                DB::table($table)->where('id', $record->id)->update(['code' => $prefix.str_pad((string) ++$number, 3, '0', STR_PAD_LEFT)]);
            }
            DB::statement("CREATE UNIQUE INDEX {$table}_{$parent}_code_unique ON $table ($parent, upper(trim(code)))");
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE $table ALTER COLUMN code SET NOT NULL");
                DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_code_format CHECK (code <> '' AND code = upper(trim(code)))");
            } else {
                // Triggers avoid rebuilding tables referenced by SQLite views and foreign keys.
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    $name = strtolower($operation);
                    DB::statement("CREATE TRIGGER {$table}_code_$name BEFORE $operation ON $table WHEN NEW.code IS NULL OR NEW.code = '' OR NEW.code <> upper(trim(NEW.code)) BEGIN SELECT RAISE(ABORT, 'A normalized location code is required'); END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['zones' => 'room_id', 'rooms' => 'site_id'] as $table => $parent) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['insert', 'update'] as $operation) {
                    DB::statement("DROP TRIGGER {$table}_code_$operation");
                }
            }
            DB::statement("DROP INDEX {$table}_{$parent}_code_unique");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('code'));
        }
    }
};
