<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_role_id')->constrained('permission_roles')->restrictOnDelete();
            $table->primary(['user_id', 'permission_role_id']);
            $table->index(['permission_role_id', 'user_id']);
        });
        DB::table('permission_role_user')->insertUsing(['user_id', 'permission_role_id'], DB::table('users')->whereNotNull('permission_role_id')->select('id', 'permission_role_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex(['permission_role_id']));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('permission_role_id'));
    }

    public function down(): void
    {
        if (DB::table('permission_role_user')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore single profiles while multiple assignments exist.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE users ADD COLUMN permission_role_id varchar NULL REFERENCES permission_roles(id) ON DELETE RESTRICT');
        } else {
            Schema::table('users', fn (Blueprint $table) => $table->foreignUuid('permission_role_id')->nullable()->constrained('permission_roles')->restrictOnDelete());
        }
        DB::table('users')->update(['permission_role_id' => DB::table('permission_role_user')->select('permission_role_id')->whereColumn('user_id', 'users.id')->limit(1)]);
        Schema::table('users', fn (Blueprint $table) => $table->index('permission_role_id'));
        Schema::dropIfExists('permission_role_user');
    }
};
