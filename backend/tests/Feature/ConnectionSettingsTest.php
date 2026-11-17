<?php

namespace Tests\Feature;

use App\Models\ConnectionSetting;
use App\Models\User;
use App\Services\ConnectionConfiguration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/connection-settings/homebridge';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(config('connection_settings.homebridge')) as $name) {
            config(['connection_settings.homebridge.'.$name.'.locked' => false]);
        }
    }

    public function test_authentication_flags_can_be_saved_in_ui_and_locked_by_environment(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        config(['connection_settings.authentication.AUTH_LOCAL_ENABLED.locked' => false, 'connection_settings.authentication.AUTH_REGISTRATION_ENABLED.locked' => false]);
        $this->actingAs($admin)->patchJson('/api/v1/connection-settings/authentication', ['version' => 1, 'values' => ['AUTH_LOCAL_ENABLED' => false, 'AUTH_REGISTRATION_ENABLED' => true]])->assertOk();
        app(ConnectionConfiguration::class)->apply();
        $this->assertFalse(config('auth.local_enabled'));
        $this->assertTrue(config('auth.registration_enabled'));
        config(['connection_settings.authentication.AUTH_LOCAL_ENABLED.locked' => true]);
        $this->getJson('/api/v1/connection-settings/authentication')->assertOk()->assertJsonPath('data.fields.0.locked', true);
        $this->patchJson('/api/v1/connection-settings/authentication', ['version' => 2, 'values' => ['AUTH_LOCAL_ENABLED' => true]])->assertUnprocessable();
    }

    public function test_only_administrators_can_read_or_change_connection_configuration(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(self::URL)->assertForbidden();
        $this->patchJson(self::URL, ['version' => 1, 'values' => []])->assertForbidden();
        $this->postJson('/api/v1/connection-settings/storage/check')->assertForbidden();
    }

    public function test_environment_fields_are_locked_and_ui_secrets_are_encrypted_and_never_returned(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        config(['connection_settings.homebridge.HOMEBRIDGE_URL.locked' => true]);
        $this->actingAs($admin)->getJson(self::URL)->assertOk()->assertJsonPath('data.fields.0.locked', true);
        $this->patchJson(self::URL, ['version' => 1, 'values' => ['HOMEBRIDGE_URL' => 'https://override.example.test']])
            ->assertUnprocessable();
        $response = $this->patchJson(self::URL, ['version' => 1, 'values' => ['HOMEBRIDGE_USERNAME' => 'integration', 'HOMEBRIDGE_PASSWORD' => 'fictional-stored-secret']])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.fields.2.hasValue', true)->assertJsonPath('data.fields.2.value', null);
        $this->assertStringNotContainsString('fictional-stored-secret', $response->getContent());
        $this->assertStringNotContainsString('fictional-stored-secret', DB::table('connection_settings')->where('id', 'homebridge')->value('values'));
        $this->assertSame('fictional-stored-secret', config('homebridge.password'));
        $this->patchJson(self::URL, ['version' => 1, 'values' => ['HOMEBRIDGE_USERNAME' => 'stale']])->assertConflict();
        $this->patchJson(self::URL, ['version' => 2, 'values' => ['UNKNOWN_FIELD' => 'no']])->assertUnprocessable();
        $this->patchJson(self::URL, ['version' => 2, 'reset' => true])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertSame([], ConnectionSetting::findOrFail('homebridge')->values);
    }

    public function test_internal_webhook_exceptions_are_validated_persisted_and_environment_locked(): void
    {
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $url = '/api/v1/connection-settings/webhook';
        foreach (['*', '0.0.0.0/0', '192.168.1.0/33'] as $invalid) {
            $this->patchJson($url, ['version' => 1, 'values' => ['ALERT_WEBHOOK_ALLOWED_INTERNAL' => $invalid]])->assertUnprocessable();
        }
        $this->patchJson($url, ['version' => 1, 'values' => ['ALERT_WEBHOOK_ALLOWED_INTERNAL' => 'ntfy.lan,192.168.10.0/24']])->assertOk();
        $this->assertSame('ntfy.lan,192.168.10.0/24', config('alert_notifications.webhook.allowed_internal'));
        config(['connection_settings.webhook.ALERT_WEBHOOK_ALLOWED_INTERNAL.locked' => true]);
        $this->patchJson($url, ['version' => 2, 'values' => ['ALERT_WEBHOOK_ALLOWED_INTERNAL' => 'changed.lan']])->assertUnprocessable();
    }

    public function test_configuration_applies_to_provider_calls_but_environment_wins_over_saved_values(): void
    {
        $row = ConnectionSetting::findOrFail('homebridge');
        $row->values = ['HOMEBRIDGE_URL' => 'https://saved.example.test', 'HOMEBRIDGE_PASSWORD' => 'saved-secret'];
        $row->save();
        $arguments = $_SERVER['argv'];
        try {
            $_SERVER['argv'] = ['artisan', 'config:cache'];
            app(ConnectionConfiguration::class)->apply();
            $this->assertNotSame('saved-secret', config('homebridge.password'));
        } finally {
            $_SERVER['argv'] = $arguments;
        }
        app(ConnectionConfiguration::class)->apply();
        $this->assertSame('https://saved.example.test', config('homebridge.url'));
        config(['connection_settings.homebridge.HOMEBRIDGE_URL.locked' => true]);
        app(ConnectionConfiguration::class)->apply();
        $this->assertNotSame('https://saved.example.test', config('homebridge.url'));
    }

    public function test_mail_security_tracks_saved_port_and_obsolete_fields_are_not_exposed(): void
    {
        foreach (config('connection_settings.mail') as $name => $field) {
            config(['connection_settings.mail.'.$name.'.locked' => false]);
        }
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $this->patchJson('/api/v1/connection-settings/mail', ['version' => 1, 'values' => ['MAIL_PORT' => 465]])->assertOk();
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->patchJson('/api/v1/connection-settings/mail', ['version' => 2, 'values' => ['MAIL_PORT' => 587]])->assertOk();
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $names = array_column(app(ConnectionConfiguration::class)->payload('mail')['fields'], 'name');
        $this->assertNotContains('MAIL_URL', $names);
        $this->assertNotContains('MAIL_SCHEME', $names);
        $this->patchJson(self::URL, ['version' => 1, 'values' => ['HOMEBRIDGE_TOKEN' => 'removed']])->assertUnprocessable();
    }

    public function test_obsolete_connection_secrets_are_removed_without_losing_credentials(): void
    {
        $row = ConnectionSetting::findOrFail('homebridge');
        $row->update(['values' => ['HOMEBRIDGE_TOKEN' => 'obsolete', 'HOMEBRIDGE_USERNAME' => 'operator', 'HOMEBRIDGE_PASSWORD' => 'retained-secret']]);
        $migration = require database_path('migrations/2026_11_17_040000_remove_obsolete_connection_fields.php');
        $migration->up();
        $row->refresh();
        $this->assertSame(['HOMEBRIDGE_USERNAME' => 'operator', 'HOMEBRIDGE_PASSWORD' => 'retained-secret'], $row->values);
        $version = $row->version;
        $migration->up();
        $this->assertSame($version, $row->fresh()->version);
        $this->assertStringNotContainsString('retained-secret', DB::table('connection_settings')->where('id', 'homebridge')->value('values'));
    }

    public function test_invalid_urls_ports_and_unexpected_groups_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $this->patchJson(self::URL, ['version' => 1, 'values' => ['HOMEBRIDGE_URL' => 'https://user:secret@example.test']])->assertUnprocessable();
        config(['connection_settings.mqtt.MQTT_PORT.locked' => false]);
        $this->patchJson('/api/v1/connection-settings/mqtt', ['version' => 1, 'values' => ['MQTT_PORT' => 70000]])->assertUnprocessable();
        $this->getJson('/api/v1/connection-settings/database')->assertNotFound();
    }
}
