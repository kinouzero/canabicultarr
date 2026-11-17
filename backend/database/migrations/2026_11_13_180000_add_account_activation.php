<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_activation_required')->default(false);
        });
        Schema::create('account_activation_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_activation_tokens');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_activation_required'));
    }
};
