<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bluetooth_devices', fn (Blueprint $table) => $table->text('address')->nullable());
    }

    public function down(): void
    {
        if (DB::table('bluetooth_devices')->whereNotNull('address')->exists()) {
            throw new RuntimeException('Retain configured Bluetooth radio identities; restore a backup instead of dropping them.');
        }
        Schema::table('bluetooth_devices', fn (Blueprint $table) => $table->dropColumn('address'));
    }
};
