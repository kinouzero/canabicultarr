<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rp_id');
            $table->char('credential_hash', 64);
            $table->string('name', 80);
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['rp_id', 'credential_hash']);
            $table->index(['user_id', 'rp_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
