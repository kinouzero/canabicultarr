<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_retention_policies', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('days')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('approved_session_version')->nullable();
            $table->timestampTz('approved_at', 6)->nullable();
            $table->timestampsTz();
        });
        DB::table('measurement_retention_policies')->insert(['id' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('measurement_purge_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('confirmation_key', 64)->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('actor_uuid');
            $table->unsignedInteger('actor_session_version');
            $table->enum('mode', ['manual', 'policy']);
            $table->unsignedInteger('days');
            $table->unsignedInteger('policy_version');
            $table->timestampTz('cutoff_at', 6);
            $table->enum('status', ['queued', 'running', 'completed', 'cancelled', 'failed'])->default('queued')->index();
            $table->unsignedBigInteger('deleted_count')->default(0);
            $table->timestampTz('completed_at', 6)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        if (DB::table('measurement_purge_runs')->exists() || DB::table('measurement_retention_policies')->whereNotNull('approved_at')->exists()) {
            throw new RuntimeException('Retain confirmed retention history; restore a backup instead.');
        }
        Schema::dropIfExists('measurement_purge_runs');
        Schema::dropIfExists('measurement_retention_policies');
    }
};
