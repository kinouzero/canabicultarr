<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('sensor_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->string('kind', 16);
            $t->string('severity', 16);
            $t->string('minimum', 32)->nullable();
            $t->string('maximum', 32)->nullable();
            $t->unsignedInteger('delay_seconds')->nullable();
            $t->unsignedInteger('max_age_seconds')->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('activated_at', 6);
            $t->timestampTz('last_evaluated_at', 6)->nullable();
            $t->string('last_result', 16)->default('waiting');
            $t->timestamps();
            $t->index(['is_enabled', 'last_evaluated_at']);
        });
        Schema::create('alert_episodes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('alert_rule_id')->constrained()->restrictOnDelete();
            $t->json('rule_snapshot');
            $t->string('severity', 16);
            $t->string('reason', 32);
            $t->json('first_observation')->nullable();
            $t->json('last_observation')->nullable();
            $t->json('resolution_observation')->nullable();
            $t->timestampTz('opened_at', 6);
            $t->timestampTz('last_detected_at', 6);
            $t->timestampTz('resolved_at', 6)->nullable()->index();
            $t->string('resolution', 32)->nullable();
            $t->timestampTz('acknowledged_at', 6)->nullable();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->uuid('acknowledger_uuid')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX alert_episodes_one_open ON alert_episodes(alert_rule_id) WHERE resolved_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_episodes');
        Schema::dropIfExists('alert_rules');
    }
};
