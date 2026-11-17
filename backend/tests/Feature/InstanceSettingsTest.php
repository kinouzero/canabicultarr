<?php

namespace Tests\Feature;

use App\Models\InstanceSetting;
use App\Models\User;
use App\Services\InstanceSettings;
use App\Support\ThemePalette;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InstanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['oidc.provider_name' => null, 'oidc.provider_icon' => null]);
    }

    public function test_active_members_read_instance_defaults_but_guests_and_inactive_users_cannot(): void
    {
        $this->getJson('/api/v1/instance-settings')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/instance-settings')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.dateFormat', 'd/m/Y')->assertJsonPath('data.timeFormat', '24')
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.canManage', false);
        $this->actingAs(User::factory()->create(['is_active' => false]))->getJson('/api/v1/instance-settings')->assertUnauthorized();
    }

    public function test_only_admins_update_versioned_settings_and_noop_keeps_version(): void
    {
        $data = ['date_format' => 'Y-m-d', 'time_format' => '12', 'version' => 1];
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/instance-settings', $data)->assertForbidden();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($admin)->patchJson('/api/v1/instance-settings', $data)->assertOk()
            ->assertJsonPath('data.dateFormat', 'Y-m-d')->assertJsonPath('data.timeFormat', '12')
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.canManage', true);
        $data['version'] = 2;
        $this->patchJson('/api/v1/instance-settings', $data)->assertOk()->assertJsonPath('data.version', 2);
        $data['version'] = 1;
        $this->patchJson('/api/v1/instance-settings', $data)->assertConflict()->assertJsonPath('error.code', 'instanceSettings.conflict');
        $this->actingAs(User::factory()->create())->getJson('/api/v1/instance-settings')->assertOk()->assertJsonPath('data.dateFormat', 'Y-m-d');
    }

    public function test_formats_are_validated_without_mutating_settings(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        foreach ([['date_format' => 'arbitrary'], ['time_format' => '25'], ['date_format' => null], ['version' => 0], ['version' => null]] as $invalid) {
            $this->actingAs($admin)->patchJson('/api/v1/instance-settings', array_replace(['date_format' => 'd/m/Y', 'time_format' => '24', 'version' => 1], $invalid))->assertUnprocessable();
        }
        $this->assertSame(1, InstanceSetting::findOrFail(1)->version);
        foreach (['d/m/Y', 'd.m.Y', 'd-m-Y', 'm/d/Y', 'Y-m-d', 'localized-short', 'localized', 'localized-long', 'localized-full'] as $format) {
            $this->patchJson('/api/v1/instance-settings', ['date_format' => $format, 'time_format' => '24', 'version' => InstanceSetting::findOrFail(1)->version])->assertOk()->assertJsonPath('data.dateFormat', $format);
        }
    }

    public function test_genetics_palette_is_versioned_validated_and_preserved_by_date_only_updates(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $url = '/api/v1/instance-settings';
        $this->actingAs($admin)->getJson($url)->assertJsonPath('data.geneticsColors', ['sativa' => '#c75b50', 'indica' => '#527eae', 'hybrid' => '#b9922e']);
        $data = ['date_format' => 'd/m/Y', 'time_format' => '24', 'version' => 1];
        $colors = ['sativa' => '#008844', 'indica' => '#3355AA', 'hybrid' => '#DDAA33'];
        foreach ([null, [], ['sativa' => '#123456'], [...$colors, 'indica' => 'url(javascript:bad)'], [...$colors, 'extra' => '#123456'], [...$colors, 'sativa' => '#fff']] as $invalid) {
            $this->patchJson($url, [...$data, 'genetics_colors' => $invalid])->assertUnprocessable();
        }
        $this->assertSame(1, InstanceSetting::findOrFail(1)->version);
        $this->patchJson($url, [...$data, 'genetics_colors' => $colors])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.geneticsColors.indica', '#3355aa');
        $this->patchJson($url, [...$data, 'version' => 2, 'genetics_colors' => $colors])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($url, [...$data, 'genetics_colors' => $colors])->assertConflict();
        $this->patchJson($url, [...$data, 'version' => 2, 'date_format' => 'Y-m-d'])->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.geneticsColors.sativa', '#008844');
        $this->actingAs(User::factory()->create())->patchJson($url, [...$data, 'version' => 3, 'genetics_colors' => $colors])->assertForbidden();
        $this->getJson($url)->assertOk()->assertJsonPath('data.geneticsColors.sativa', '#008844');
    }

    public function test_public_branding_exposes_only_identity_and_version(): void
    {
        $this->getJson('/api/v1/branding')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => ['oidcProviderName' => 'Authentik', 'oidcProviderIcon' => null, 'siteTitle' => 'Canabicultarr', 'siteSubtitle' => null, 'version' => 1]]);
    }

    public function test_branding_is_shared_versioned_preserved_and_clearable(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $data = ['date_format' => 'd/m/Y', 'time_format' => '24', 'version' => 1, 'site_title' => '  My Garden  ', 'site_subtitle' => '  Plants & notes  '];
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/instance-settings', $data)->assertForbidden();
        $this->actingAs($admin)->patchJson('/api/v1/instance-settings', $data)->assertOk()
            ->assertJsonPath('data.siteTitle', 'My Garden')->assertJsonPath('data.siteSubtitle', 'Plants & notes')->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/instance-settings', [...$data, 'version' => 2])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/instance-settings', [...$data, 'site_title' => 'Stale'])->assertConflict();
        $this->patchJson('/api/v1/instance-settings', ['date_format' => 'Y-m-d', 'time_format' => '24', 'version' => 2])->assertOk()->assertJsonPath('data.siteTitle', 'My Garden')->assertJsonPath('data.siteSubtitle', 'Plants & notes');
        $this->patchJson('/api/v1/instance-settings', [...$data, 'version' => 3, 'site_subtitle' => ''])->assertOk()->assertJsonPath('data.siteSubtitle', null)->assertJsonPath('data.version', 4);
        $this->getJson('/api/v1/branding')->assertExactJson(['data' => ['oidcProviderName' => 'Authentik', 'oidcProviderIcon' => null, 'siteTitle' => 'My Garden', 'siteSubtitle' => null, 'version' => 4]]);
    }

    public function test_oidc_provider_label_is_admin_only_validated_versioned_and_public(): void
    {
        $url = '/api/v1/instance-settings';
        $data = ['date_format' => 'd/m/Y', 'time_format' => '24', 'version' => 1, 'oidc_provider_name' => '  Garden SSO  '];
        $this->actingAs(User::factory()->create())->patchJson($url, $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        foreach ([null, '', '   ', str_repeat('a', 81), ['invalid'], "Line\nBreak"] as $invalid) {
            $this->patchJson($url, [...$data, 'oidc_provider_name' => $invalid])->assertUnprocessable();
        }
        $this->patchJson($url, $data)->assertOk()->assertJsonPath('data.oidcProviderName', 'Garden SSO')->assertJsonPath('data.version', 2);
        $this->patchJson($url, [...$data, 'version' => 2])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($url, $data)->assertConflict();
        $this->patchJson($url, ['date_format' => 'Y-m-d', 'time_format' => '24', 'version' => 2])->assertOk()->assertJsonPath('data.oidcProviderName', 'Garden SSO');
        $this->getJson('/api/v1/branding')->assertExactJson(['data' => ['oidcProviderName' => 'Garden SSO', 'oidcProviderIcon' => null, 'siteTitle' => 'Canabicultarr', 'siteSubtitle' => null, 'version' => 3]]);
    }

    public function test_environment_provider_name_is_public_locked_and_preserves_database_fallback(): void
    {
        InstanceSetting::findOrFail(1)->update(['oidc_provider_name' => 'Stored identity']);
        config(['oidc.provider_name' => '  Environment identity  ']);
        $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('data.oidcProviderName', 'Environment identity');
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $this->getJson('/api/v1/instance-settings')->assertOk()->assertJsonPath('data.oidcProviderNameLocked', true);
        $data = ['date_format' => 'Y-m-d', 'time_format' => '24', 'version' => 1];
        $this->patchJson('/api/v1/instance-settings', [...$data, 'oidc_provider_name' => 'Override'])->assertUnprocessable();
        $this->patchJson('/api/v1/instance-settings', $data)->assertOk()->assertJsonPath('data.oidcProviderName', 'Environment identity');
        $this->assertSame('Stored identity', InstanceSetting::findOrFail(1)->oidc_provider_name);
        config(['oidc.provider_name' => '  ']);
        $this->getJson('/api/v1/instance-settings')->assertOk()->assertJsonPath('data.oidcProviderNameLocked', false)->assertJsonPath('data.oidcProviderName', 'Stored identity');
    }

    public function test_provider_logo_is_authorized_validated_versioned_and_can_be_cleared(): void
    {
        $data = ['date_format' => 'd/m/Y', 'time_format' => '24', 'version' => 1, 'oidc_provider_icon' => 'authentik'];
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/instance-settings', $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        foreach (['../authentik', 'https://example.test/icon', 'foo.svg', '<svg>', ['invalid'], str_repeat('a', 101)] as $invalid) {
            $this->patchJson('/api/v1/instance-settings', [...$data, 'oidc_provider_icon' => $invalid])->assertUnprocessable();
        }
        $this->patchJson('/api/v1/instance-settings', $data)->assertOk()->assertJsonPath('data.oidcProviderIcon', 'authentik')->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/branding')->assertJsonPath('data.oidcProviderIcon', 'authentik');
        $this->patchJson('/api/v1/instance-settings', $data)->assertConflict();
        $this->patchJson('/api/v1/instance-settings', [...$data, 'version' => 2, 'oidc_provider_icon' => null])->assertOk()->assertJsonPath('data.oidcProviderIcon', null);
        $this->assertNull(InstanceSetting::findOrFail(1)->oidc_provider_icon);
    }

    public function test_environment_logo_is_locked_and_never_exposes_arbitrary_urls(): void
    {
        InstanceSetting::findOrFail(1)->update(['oidc_provider_icon' => 'keycloak']);
        config(['oidc.provider_icon' => ' authentik ']);
        $this->getJson('/api/v1/branding')->assertJsonPath('data.oidcProviderIcon', 'authentik');
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $this->getJson('/api/v1/instance-settings')->assertJsonPath('data.oidcProviderIconLocked', true);
        $data = ['date_format' => 'd/m/Y', 'time_format' => '24', 'version' => 1];
        $this->patchJson('/api/v1/instance-settings', [...$data, 'oidc_provider_icon' => 'override'])->assertUnprocessable();
        $this->patchJson('/api/v1/instance-settings', $data)->assertOk();
        $this->assertSame('keycloak', InstanceSetting::findOrFail(1)->oidc_provider_icon);
        config(['oidc.provider_icon' => 'https://untrusted.test/icon.svg']);
        $this->getJson('/api/v1/branding')->assertJsonPath('data.oidcProviderIcon', null);
        config(['oidc.provider_icon' => null]);
        $this->getJson('/api/v1/instance-settings')->assertJsonPath('data.oidcProviderIconLocked', false)->assertJsonPath('data.oidcProviderIcon', 'keycloak');
    }

    public function test_invalid_branding_cannot_mutate_settings(): void
    {
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $data = ['date_format' => 'd/m/Y', 'time_format' => '24', 'version' => 1];
        foreach ([['site_title' => null], ['site_title' => '   '], ['site_title' => str_repeat('a', 81)], ['site_title' => ['invalid']], ['site_subtitle' => str_repeat('a', 161)], ['site_subtitle' => ['invalid']], ['site_title' => "Line\nBreak"], ['site_subtitle' => "Line\nBreak"]] as $invalid) {
            $this->patchJson('/api/v1/instance-settings', [...$data, ...$invalid])->assertUnprocessable();
        }
        $this->assertSame(1, InstanceSetting::findOrFail(1)->version);
        $this->assertSame('Canabicultarr', InstanceSetting::findOrFail(1)->site_title);
    }

    public function test_new_theme_colors_keep_existing_instance_customizations(): void
    {
        $theme = ThemePalette::defaults();
        $theme['preset'] = 'custom';
        $theme['light']['accent'] = '#123456';
        unset($theme['light']['actionWater'], $theme['dark']['photoOverlay']);
        InstanceSetting::findOrFail(1)->update(['theme' => $theme]);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/instance-settings')
            ->assertOk()->assertJsonPath('data.theme.light.accent', '#123456')
            ->assertJsonPath('data.theme.light.actionWater', '#1767a6')
            ->assertJsonPath('data.theme.dark.photoOverlay', '#08110c');
    }

    public function test_theme_palettes_are_complete_validated_versioned_and_preserved(): void
    {
        $url = '/api/v1/instance-settings';
        $theme = ThemePalette::defaults();
        $data = ['date_format' => 'd/m/Y', 'time_format' => '24', 'version' => 1];
        $this->actingAs(User::factory()->create())->getJson($url)->assertOk()->assertJsonPath('data.theme', $theme);
        $this->patchJson($url, [...$data, 'theme' => $theme])->assertForbidden();
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $missing = $theme;
        unset($missing['dark']['accent']);
        foreach ([null, [], $missing, [...$theme, 'preset' => 'unknown'], [...$theme, 'light' => [...$theme['light'], 'extra' => '#123456']], [...$theme, 'dark' => [...$theme['dark'], 'surface' => 'url(javascript:bad)']], [...$theme, 'light' => [...$theme['light'], 'text' => '#fff']]] as $invalid) {
            $this->patchJson($url, [...$data, 'theme' => $invalid])->assertUnprocessable();
        }
        $this->assertSame(1, InstanceSetting::findOrFail(1)->version);
        $theme['preset'] = 'custom';
        $theme['light']['accent'] = '#3355AA';
        $theme['light']['actionWater'] = '#1234AB';
        $theme['dark']['shadow'] = '#112233';
        $theme['dark']['accent'] = '#88AACC';
        $this->patchJson($url, [...$data, 'theme' => $theme])->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.theme.light.accent', '#3355aa')->assertJsonPath('data.theme.dark.accent', '#88aacc')
            ->assertJsonPath('data.theme.light.actionWater', '#1234ab')->assertJsonPath('data.theme.dark.shadow', '#112233');
        $this->patchJson($url, [...$data, 'version' => 2, 'theme' => $theme])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($url, [...$data, 'theme' => $theme])->assertConflict();
        $this->patchJson($url, [...$data, 'version' => 2, 'date_format' => 'Y-m-d'])->assertOk()
            ->assertJsonPath('data.theme.preset', 'custom')->assertJsonPath('data.theme.light.accent', '#3355aa');
        $this->getJson('/api/v1/branding')->assertJsonMissingPath('data.theme');
    }

    public function test_service_rechecks_persisted_actor_permissions_and_session_version(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $data = ['date_format' => 'm/d/Y', 'time_format' => '12', 'version' => 1];
        $admin->fresh()->permissionRoles()->sync([AccessProfileSeeder::defaultProfile()->id]);
        try {
            app(InstanceSettings::class)->save($admin, $data);
            $this->fail('A stale administrator must not write settings.');
        } catch (AuthorizationException) {
            $this->assertSame(1, InstanceSetting::findOrFail(1)->version);
        }
        $admin->fresh()->permissionRoles()->sync([AccessProfileSeeder::defaultProfile('administrator')->id]);
        User::whereKey($admin->id)->update(['session_version' => $admin->session_version + 1]);
        $this->expectException(AuthenticationException::class);
        app(InstanceSettings::class)->save($admin, $data);
    }
}
