<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bluetooth_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->unique()->constrained()->restrictOnDelete();
            $table->string('device_key', 64)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('bluetooth_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bluetooth_device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('sensor_id')->unique()->constrained()->restrictOnDelete();
            $table->string('entity_id', 32);
            $table->string('instance_key', 64);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('last_attempt_at', 6)->nullable();
            $table->timestampTz('last_success_at', 6)->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['bluetooth_device_id', 'entity_id']);
        });
        $this->alterMeasurements(fn (Blueprint $table) => $table->foreignUuid('bluetooth_link_id')->nullable()->constrained()->restrictOnDelete());
    }

    public function down(): void
    {
        if (DB::table('measurements')->whereNotNull('bluetooth_link_id')->exists()) {
            throw new RuntimeException('Retain Bluetooth measurement history; restore a backup instead of dropping it.');
        }
        $this->alterMeasurements(fn (Blueprint $table) => $table->dropConstrainedForeignId('bluetooth_link_id'));
        Schema::dropIfExists('bluetooth_links');
        Schema::dropIfExists('bluetooth_devices');
    }

    private function alterMeasurements(Closure $alter): void
    {
        // SQLite rebuilds a table to add/remove foreign keys; Laravel does not retain triggers.
        $triggers = DB::getDriverName() === 'sqlite'
            ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'measurements'")
            : [];
        Schema::table('measurements', $alter);
        foreach ($triggers as $trigger) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                DB::unprepared($trigger->sql);
            }
        }
    }
};
