<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique();
            $table->string('role', 16)->default('member')->index();
        });

        DB::table('users')->orderBy('id')->eachById(function (object $user) {
            DB::table('users')->where('id', $user->id)->update(['uuid' => (string) Str::uuid()]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });

        Schema::create('application_setup', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('token_hash', 64)->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
        });

        DB::table('application_setup')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('application_setup');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropIndex(['role']);
            $table->dropColumn(['uuid', 'role']);
        });
    }
};
