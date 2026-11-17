<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('connection_settings')->insert(['id' => 'authentication', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('connection_settings')->where('id', 'authentication')->delete();
    }
};
