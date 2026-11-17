<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('parent_key', 36);
            $table->string('name', 120);
            $table->string('name_key', 120);
            $table->unique(['parent_key', 'name_key']);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::table('document_folders', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('document_folders')->restrictOnDelete());
        if (DB::getDriverName() === 'sqlite') {
            // Native inline references preserve incoming plant/event document links on rollback.
            DB::statement('ALTER TABLE documents ADD COLUMN folder_id varchar REFERENCES document_folders(id) ON DELETE RESTRICT');
        } else {
            Schema::table('documents', fn (Blueprint $table) => $table->foreignUuid('folder_id')->nullable()->constrained('document_folders')->restrictOnDelete());
        }
        Schema::table('documents', fn (Blueprint $table) => $table->index('folder_id'));
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropIndex(['folder_id']));
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE documents DROP COLUMN folder_id');
        } else {
            Schema::table('documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('folder_id'));
        }
        Schema::dropIfExists('document_folders');
    }
};
