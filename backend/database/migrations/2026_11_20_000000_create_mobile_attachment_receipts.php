<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_attachment_receipts', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('upload_id');
            $table->uuid('plant_id');
            $table->foreignUuid('document_id')->constrained('documents')->restrictOnDelete();
            $table->string('digest', 64);
            $table->primary(['user_id', 'upload_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_attachment_receipts');
    }
};
