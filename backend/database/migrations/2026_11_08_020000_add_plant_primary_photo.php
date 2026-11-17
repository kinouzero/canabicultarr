<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE plants ADD COLUMN photo_document_id varchar NULL REFERENCES documents(id) ON DELETE RESTRICT');
        } else {
            Schema::table('plants', fn (Blueprint $table) => $table->foreignUuid('photo_document_id')->nullable()->constrained('documents')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        Schema::table('plants', fn (Blueprint $table) => $table->dropConstrainedForeignId('photo_document_id'));
    }
};
