<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('actor_uuid');
            $table->unsignedInteger('actor_session_version');
            $table->uuid('request_key');
            $table->string('access_fingerprint', 64);
            $table->foreignUuid('sensor_id')->nullable()->constrained()->nullOnDelete();
            $table->json('criteria');
            $table->json('display');
            $table->enum('format', ['csv', 'pdf']);
            $table->enum('locale', ['en', 'fr']);
            $table->string('timezone', 64);
            $table->enum('disk', ['documents', 'documents_s3']);
            $table->enum('status', ['queued', 'processing', 'ready', 'cancelled', 'failed', 'expired'])->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestampTz('expires_at', 6)->index();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampsTz();
            $table->unique(['requested_by', 'request_key']);
            $table->index(['requested_by', 'sensor_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('measurement_report_exports')->exists()) {
            throw new RuntimeException('Retain report export audit history; restore a backup instead.');
        }
        Schema::dropIfExists('measurement_report_exports');
    }
};
