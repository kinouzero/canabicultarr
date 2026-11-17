<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer', 512);
            $table->string('subject', 255);
            $table->timestamps();
            $table->unique(['issuer', 'subject']);
            $table->unique(['user_id', 'issuer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_identities');
    }
};
