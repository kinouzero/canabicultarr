<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->boolean('email_enabled')->default(false);
            $t->boolean('push_enabled')->default(false);
            $t->boolean('webhook_enabled')->default(false);
            $t->boolean('include_recovery')->default(true);
            $t->string('minimum_severity', 12)->default('info');
            $t->string('locale', 2)->default('en');
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
        });
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->boolean('email_enabled')->default(false);
            $t->boolean('push_enabled')->default(false);
            $t->string('locale', 2)->default('en');
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
        });
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->char('endpoint_hash', 64)->unique();
            $t->char('session_hash', 64);
            $t->text('endpoint');
            $t->text('keys');
            $t->char('vapid_key_hash', 64);
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('last_used_at')->nullable();
            $t->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('alert_episode_id')->constrained('alert_episodes')->restrictOnDelete();
            $t->string('event', 16);
            $t->string('channel', 16);
            $t->string('recipient_key', 80);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('push_subscription_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('settings_version');
            $t->char('transport_key', 64);
            $t->unsignedInteger('preference_version')->nullable();
            $t->unsignedInteger('user_version')->nullable();
            $t->unsignedInteger('subscription_version')->nullable();
            $t->text('payload');
            $t->string('status', 16)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestampTz('next_attempt_at');
            $t->uuid('lease_token')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->timestamps();
            $t->unique(['alert_episode_id', 'event', 'channel', 'recipient_key'], 'notification_delivery_recipient_unique');
            $t->index(['status', 'next_attempt_at']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_settings');
    }
};
