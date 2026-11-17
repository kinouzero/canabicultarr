<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('name_key', 80)->unique();
            $table->string('icon', 32);
            $table->json('contexts');
            $table->boolean('is_archived')->default(false)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        if (DB::getDriverName() === 'sqlite') {
            // Native column removal keeps incoming document/revision references intact.
            DB::statement('ALTER TABLE event_entries ADD COLUMN event_type_id varchar REFERENCES event_types(id) ON DELETE RESTRICT');
        } else {
            Schema::table('event_entries', fn (Blueprint $table) => $table->foreignUuid('event_type_id')->nullable()->constrained('event_types')->restrictOnDelete());
        }
        Schema::table('event_entries', function (Blueprint $table) {
            $table->json('event_type_snapshot')->nullable();
            $table->index('event_type_id');
        });
        $condition = "(category = 'custom' AND event_type_id IS NOT NULL AND event_type_snapshot IS NOT NULL) OR (category IN ('observation','maintenance','incident','note') AND event_type_id IS NULL AND event_type_snapshot IS NULL)";
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE event_entries ADD CONSTRAINT event_entries_type_consistency CHECK ($condition)");
        } else {
            $newCondition = str_replace(['category', 'event_type_id', 'event_type_snapshot'], ['NEW.category', 'NEW.event_type_id', 'NEW.event_type_snapshot'], $condition);
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $name = strtolower($operation);
                DB::statement("CREATE TRIGGER event_entries_type_$name BEFORE $operation ON event_entries WHEN NOT ($newCondition) BEGIN SELECT RAISE(ABORT, 'Event category and custom type must agree'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('event_entries')->whereNotNull('event_type_id')->exists() || DB::table('event_revisions')->where('snapshot->category', 'custom')->exists()) {
            throw new RuntimeException('Retain custom journal event history; restore a pre-migration backup for a full downgrade.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE event_entries DROP CONSTRAINT event_entries_type_consistency');
        } else {
            DB::statement('DROP TRIGGER event_entries_type_insert');
            DB::statement('DROP TRIGGER event_entries_type_update');
        }
        Schema::table('event_entries', fn (Blueprint $table) => $table->dropIndex(['event_type_id']));
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE event_entries DROP COLUMN event_type_id');
        } else {
            Schema::table('event_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('event_type_id'));
        }
        Schema::table('event_entries', fn (Blueprint $table) => $table->dropColumn('event_type_snapshot'));
        Schema::drop('event_types');
    }
};
