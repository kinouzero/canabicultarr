<?php

use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $administrator = AccessProfileSeeder::defaultProfile('administrator');
        $observer = AccessProfileSeeder::defaultProfile();
        DB::table('users')->where('role', 'admin')->update(['permission_role_id' => $administrator->id, 'permission_grants' => '[]', 'location_access' => null]);
        DB::table('users')->whereNull('permission_role_id')->update(['permission_role_id' => $observer->id]);
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex('users_role_index'));
        Schema::table('users', function (Blueprint $table) {
            $table->json('permission_denials')->nullable();
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('member')->index();
        });
        DB::table('users')->whereIn('permission_role_id', DB::table('permission_roles')->whereJsonContains('permissions', 'administration.view')->select('id'))->update(['role' => 'admin']);
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('permission_denials'));
    }
};
