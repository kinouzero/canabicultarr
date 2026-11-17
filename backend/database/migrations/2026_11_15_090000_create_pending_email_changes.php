<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_email_changes', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->index()->constrained('users')->cascadeOnDelete();
            $table->string('email', 254)->unique();
            $table->char('token_hash', 64);
            $table->unsignedInteger('user_version');
            $table->unsignedInteger('requester_version');
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('queued_at', 6)->nullable();
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_email_changes');
    }
};
