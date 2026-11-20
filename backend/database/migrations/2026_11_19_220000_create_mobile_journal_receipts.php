<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_journal_receipts', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('payload_digest', 64);
            $table->foreignUuid('event_id')->constrained('event_entries')->restrictOnDelete();
            $table->primary(['user_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_journal_receipts');
    }
};
