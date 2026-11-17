<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->text('search_text');
            $table->string('original_name');
            $table->string('media_type', 100);
            $table->string('format', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('disk', 32);
            $table->string('path')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_archived')->default(false)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('uploader_uuid');
            $table->string('subject_kind', 16)->nullable();
            $table->json('subject_snapshot')->nullable();
            foreach (['site' => 'sites', 'room' => 'rooms', 'zone' => 'zones', 'asset' => 'assets', 'sensor' => 'sensors', 'event_entry' => 'event_entries'] as $name => $parent) {
                $table->foreignUuid($name.'_id')->nullable()->constrained($parent)->restrictOnDelete();
                $table->index($name.'_id');
            }
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
