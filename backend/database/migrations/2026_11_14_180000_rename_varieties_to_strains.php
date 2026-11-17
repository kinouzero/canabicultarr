<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->rename('varieties', 'strains', 'variety', 'strain');
    }

    public function down(): void
    {
        $this->rename('strains', 'varieties', 'strain', 'variety');
    }

    private function rename(string $fromTable, string $toTable, string $from, string $to): void
    {
        // SQLite's native renames preserve populated dependent tables and their foreign keys.
        // Rebuilding a referenced table inside a migration transaction would violate those keys.
        $rebuildForeignKeys = DB::getDriverName() !== 'sqlite';
        if ($rebuildForeignKeys) {
            Schema::table('plants', fn (Blueprint $table) => $table->dropForeign([$from.'_id']));
            Schema::table($from.'_photos', function (Blueprint $table) use ($from) {
                $table->dropForeign([$from.'_id']);
                $table->dropForeign(['document_id']);
            });
            Schema::table($fromTable, fn (Blueprint $table) => $table->dropForeign(['seed_inventory_item_id']));
        }
        Schema::rename($fromTable, $toTable);
        Schema::rename($from.'_photos', $to.'_photos');
        Schema::table('plants', fn (Blueprint $table) => $table->renameColumn($from.'_id', $to.'_id'));
        Schema::table($to.'_photos', fn (Blueprint $table) => $table->renameColumn($from.'_id', $to.'_id'));
        foreach ([$toTable, $to.'_photos', 'plants'] as $name) {
            foreach (Schema::getIndexes($name) as $index) {
                $renamed = str_replace([$fromTable, $from.'_'], [$toTable, $to.'_'], $index['name']);
                if ($renamed !== $index['name'] && ! str_starts_with($index['name'], 'sqlite_')) {
                    Schema::table($name, fn (Blueprint $table) => $table->renameIndex($index['name'], $renamed));
                }
            }
        }
        if ($rebuildForeignKeys) {
            Schema::table('plants', fn (Blueprint $table) => $table->foreign($to.'_id')->references('id')->on($toTable)->restrictOnDelete());
            Schema::table($to.'_photos', function (Blueprint $table) use ($to, $toTable) {
                $table->foreign($to.'_id')->references('id')->on($toTable)->cascadeOnDelete();
                $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            });
            Schema::table($toTable, fn (Blueprint $table) => $table->foreign('seed_inventory_item_id')->references('id')->on('inventory_items')->nullOnDelete());
        }

        // Change only application-owned keys, never user comments, notes or names.
        $this->jsonKeys('plants', 'duration_snapshot', [$from.'_version' => $to.'_version']);
        $this->jsonKeys('plant_events', 'snapshot', [$from.'_id' => $to.'_id']);
        DB::table('plant_events')->select(['id', 'fields'])->orderBy('id')->chunkById(250, function ($rows) use ($from, $to) {
            foreach ($rows as $row) {
                $fields = json_decode($row->fields, true, 512, JSON_THROW_ON_ERROR);
                $next = array_map(fn ($field) => $field === $from.'_id' ? $to.'_id' : $field, $fields);
                if ($fields !== $next) {
                    DB::table('plant_events')->where('id', $row->id)->update(['fields' => json_encode($next, JSON_THROW_ON_ERROR)]);
                }
            }
        });
        DB::table('documents')->select(['id', 'subject_snapshot'])->where('subject_kind', $from)->orderBy('id')->chunkById(250, function ($rows) use ($to) {
            foreach ($rows as $row) {
                $snapshot = json_decode($row->subject_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $snapshot['type'] = $to;
                DB::table('documents')->where('id', $row->id)->update(['subject_kind' => $to, 'subject_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    private function jsonKeys(string $table, string $column, array $keys): void
    {
        DB::table($table)->select(['id', $column])->orderBy('id')->chunkById(250, function ($rows) use ($table, $column, $keys) {
            foreach ($rows as $row) {
                $value = json_decode($row->$column, true, 512, JSON_THROW_ON_ERROR);
                $changed = false;
                foreach ($keys as $from => $to) {
                    if (array_key_exists($from, $value)) {
                        $value[$to] = $value[$from];
                        unset($value[$from]);
                        $changed = true;
                    }
                }
                if ($changed) {
                    DB::table($table)->where('id', $row->id)->update([$column => json_encode($value, JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }
};
