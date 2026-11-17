<?php

use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(AccessProfileSeeder::class)->run();
    }

    public function down(): void
    {
        // Profiles become editable application records; a rollback must not discard them.
    }
};
