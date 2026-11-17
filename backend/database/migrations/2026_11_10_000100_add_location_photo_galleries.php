<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['site' => 'sites', 'room' => 'rooms', 'zone' => 'zones'] as $kind => $table) {
            if (DB::getDriverName() === 'sqlite') {
                // An inline reference allows native DROP COLUMN without rebuilding a referenced parent table.
                DB::statement('alter table "'.$table.'" add column "photo_document_id" varchar references "documents" ("id") on delete set null');
            } else {
                Schema::table($table, function (Blueprint $table) {
                    $table->foreignUuid('photo_document_id')->nullable()->constrained('documents')->nullOnDelete();
                });
            }
            Schema::create($kind.'_photos', function (Blueprint $photos) use ($kind, $table) {
                $photos->foreignUuid($kind.'_id')->constrained($table)->cascadeOnDelete();
                $photos->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
                $photos->timestamps();
                $photos->primary([$kind.'_id', 'document_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['site' => 'sites', 'room' => 'rooms', 'zone' => 'zones'] as $kind => $table) {
            Schema::dropIfExists($kind.'_photos');
            Schema::table($table, function (Blueprint $table) {
                if (DB::getDriverName() === 'sqlite') {
                    $table->dropColumn('photo_document_id');
                } else {
                    $table->dropConstrainedForeignId('photo_document_id');
                }
            });
        }
    }
};
