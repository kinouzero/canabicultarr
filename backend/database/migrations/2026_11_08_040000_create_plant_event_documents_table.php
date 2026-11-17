<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plant_event_documents', function (Blueprint $table) {
            $table->foreignUuid('plant_event_id')->constrained('plant_events')->restrictOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->restrictOnDelete();
            $table->primary(['plant_event_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_event_documents');
    }
};
