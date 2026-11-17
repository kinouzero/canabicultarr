<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 160);
            $table->text('body');
            $table->text('search_text');
            $table->string('category', 24)->index();
            $table->string('severity', 16)->index();
            $table->timestampTz('occurred_at', 6)->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('author_uuid');
            $table->string('subject_kind', 16)->nullable();
            $table->json('subject_snapshot')->nullable();
            foreach (['site' => 'sites', 'room' => 'rooms', 'zone' => 'zones', 'asset' => 'assets', 'sensor' => 'sensors'] as $name => $parent) {
                $table->foreignUuid($name.'_id')->nullable()->constrained($parent)->restrictOnDelete();
                $table->index($name.'_id');
            }
            $table->timestamps();
        });
        Schema::create('event_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_entry_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('editor_uuid');
            $table->json('snapshot');
            $table->timestampTz('recorded_at', 6);
            $table->unique(['event_entry_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_revisions');
        Schema::dropIfExists('event_entries');
    }
};
