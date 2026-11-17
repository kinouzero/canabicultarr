<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['plant' => 'plants', 'variety' => 'varieties'] as $owner => $table) {
            Schema::create($owner.'_photos', function (Blueprint $blueprint) use ($owner, $table) {
                $blueprint->foreignUuid($owner.'_id')->constrained($table)->cascadeOnDelete();
                $blueprint->foreignUuid('document_id')->constrained()->cascadeOnDelete();
                $blueprint->timestamps();
                $blueprint->primary([$owner.'_id', 'document_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('variety_photos');
        Schema::dropIfExists('plant_photos');
    }
};
