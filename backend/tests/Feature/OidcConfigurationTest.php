<?php

namespace Tests\Feature;

use App\Models\ConnectionSetting;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OidcConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_environment_names_configure_oidc_and_lock_the_matching_ui_fields(): void
    {
        $code = 'require "vendor/autoload.php"; $config=require "config/oidc.php"; $fields=(require "config/connection_settings.php")["oidc"]; echo json_encode(["config"=>$config,"names"=>array_keys($fields),"locked"=>array_column($fields,"locked")]);';
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
            'OIDC_ENABLED' => 'true', 'OIDC_ISSUER' => 'https://identity.example.test/',
            'OIDC_CLIENT_ID' => 'fictional-client', 'OIDC_CLIENT_SECRET' => 'fictional-environment-secret',
            'OIDC_REDIRECT_URI' => 'http://localhost:8420/api/v1/auth/oidc/callback',
            'OIDC_PROVIDER_NAME' => 'Example identity', 'OIDC_PROVIDER_ICON' => 'authentik',
            'AUTHENTIK_ENABLED' => 'false', 'AUTHENTIK_CLIENT_ID' => 'ignored-old-client',
        ]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['config']['enabled']);
        $this->assertSame('fictional-client', $result['config']['client_id']);
        $this->assertSame('fictional-environment-secret', $result['config']['client_secret']);
        $this->assertSame('http://localhost:8420/api/v1/auth/oidc/callback', $result['config']['redirect']);
        $this->assertSame(['OIDC_ENABLED', 'OIDC_ISSUER', 'OIDC_CLIENT_ID', 'OIDC_CLIENT_SECRET', 'OIDC_REDIRECT_URI'], $result['names']);
        $this->assertSame([true, true, true, true, true], $result['locked']);
    }

    public function test_ui_uses_generic_names_masks_secrets_and_rejects_old_or_locked_fields(): void
    {
        foreach (array_keys(config('connection_settings.oidc')) as $name) {
            config(['connection_settings.oidc.'.$name.'.locked' => false]);
        }
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($admin);
        $url = '/api/v1/connection-settings/oidc';
        $response = $this->patchJson($url, ['version' => 1, 'values' => ['OIDC_ENABLED' => true, 'OIDC_ISSUER' => 'https://identity.example.test/', 'OIDC_CLIENT_ID' => 'fictional-ui-client', 'OIDC_CLIENT_SECRET' => 'fictional-ui-secret']])->assertOk();
        $this->assertSame('fictional-ui-secret', config('oidc.client_secret'));
        $this->assertStringNotContainsString('fictional-ui-secret', $response->getContent());
        $this->assertStringNotContainsString('fictional-ui-secret', DB::table('connection_settings')->where('id', 'oidc')->value('values'));
        $response->assertJsonPath('data.fields.3.name', 'OIDC_CLIENT_SECRET')->assertJsonPath('data.fields.3.value', null);
        $this->patchJson($url, ['version' => 2, 'values' => ['AUTHENTIK_CLIENT_ID' => 'old']])->assertUnprocessable();
        config(['connection_settings.oidc.OIDC_CLIENT_ID.locked' => true]);
        $this->patchJson($url, ['version' => 2, 'values' => ['OIDC_CLIENT_ID' => 'override']])->assertUnprocessable();
    }

    public function test_saved_connection_keys_are_renamed_without_changing_values_or_encryption(): void
    {
        $row = ConnectionSetting::findOrFail('oidc');
        $original = ['AUTHENTIK_ENABLED' => false, 'AUTHENTIK_ISSUER' => 'https://identity.example.test/', 'AUTHENTIK_CLIENT_ID' => 'fictional-client', 'AUTHENTIK_CLIENT_SECRET' => 'fictional-preserved-secret', 'AUTHENTIK_REDIRECT_URI' => 'http://localhost:8420/api/v1/auth/oidc/callback'];
        $row->values = $original;
        $row->save();
        $migration = require database_path('migrations/2026_11_17_030000_rename_oidc_connection_keys.php');
        $migration->up();
        $values = $row->fresh()->values;
        $this->assertFalse($values['OIDC_ENABLED']);
        $this->assertSame('fictional-preserved-secret', $values['OIDC_CLIENT_SECRET']);
        $this->assertArrayNotHasKey('AUTHENTIK_CLIENT_SECRET', $values);
        $this->assertSame(2, $row->fresh()->version);
        $this->assertStringNotContainsString('fictional-preserved-secret', DB::table('connection_settings')->where('id', 'oidc')->value('values'));
        $migration->up();
        $this->assertSame(2, $row->fresh()->version);
        $migration->down();
        $this->assertSame($original, $row->fresh()->values);
    }

    public function test_conflicting_duplicate_names_fail_without_discarding_configuration(): void
    {
        $row = ConnectionSetting::findOrFail('oidc');
        $row->values = ['AUTHENTIK_ENABLED' => true, 'AUTHENTIK_CLIENT_ID' => 'old-client', 'OIDC_CLIENT_ID' => 'different-client'];
        $row->save();
        $original = $row->values;
        $migration = require database_path('migrations/2026_11_17_030000_rename_oidc_connection_keys.php');
        try {
            $migration->up();
            $this->fail('Conflicting names must not silently overwrite configuration.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Conflicting OIDC connection keys', $error->getMessage());
        }
        $this->assertSame($original, $row->fresh()->values);
        $this->assertSame(1, $row->fresh()->version);
    }
}
