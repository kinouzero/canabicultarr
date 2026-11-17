<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('name_key', 120)->unique();
            $table->text('description')->nullable();
            $table->json('permissions');
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        if (DB::getDriverName() === 'sqlite') {
            // Native ADD preserves dependent rows; a table rebuild would cascade-delete them.
            DB::statement('ALTER TABLE users ADD COLUMN permission_role_id varchar NULL REFERENCES permission_roles(id) ON DELETE RESTRICT');
        } else {
            Schema::table('users', fn (Blueprint $table) => $table->foreignUuid('permission_role_id')->nullable()->constrained('permission_roles')->restrictOnDelete());
        }
        Schema::table('users', fn (Blueprint $table) => $table->index('permission_role_id'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex(['permission_role_id']));
        // Column changes may normalize SQLite's inline FK into a table constraint.
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('permission_role_id'));
        Schema::dropIfExists('permission_roles');
    }
};
