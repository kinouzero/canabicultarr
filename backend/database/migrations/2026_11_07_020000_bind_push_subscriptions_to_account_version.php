<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', fn (Blueprint $t) => $t->unsignedInteger('user_version')->nullable());
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', fn (Blueprint $t) => $t->dropColumn('user_version'));
    }
};
