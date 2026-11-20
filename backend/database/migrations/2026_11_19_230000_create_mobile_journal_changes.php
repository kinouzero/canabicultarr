<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_journal_sequence', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedBigInteger('position');
        });
        DB::table('mobile_journal_sequence')->insert(['id' => 1, 'position' => 0]);

        Schema::create('mobile_journal_changes', function (Blueprint $table) {
            $table->unsignedBigInteger('position')->primary();
            $table->uuid('event_id');
            $table->uuid('previous_site_id')->nullable();
            $table->uuid('site_id')->nullable();
            $table->index(['previous_site_id', 'position']);
            $table->index(['site_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_journal_changes');
        Schema::dropIfExists('mobile_journal_sequence');
    }
};
