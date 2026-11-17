<?php

namespace Tests\Feature;

use App\Enums\DelegatedPermission;
use App\Models\LifecycleSetting;
use App\Models\Strain;
use App\Models\User;
use App\Services\StrainCatalog;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StrainCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    public function test_guests_cannot_read_or_write_catalog_or_settings(): void
    {
        foreach (['strains', 'lifecycle-settings'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertUnauthorized();
        }
        $this->postJson('/api/v1/strains', ['name' => 'Denied'])->assertUnauthorized();
        $this->patchJson('/api/v1/lifecycle-settings', [])->assertUnauthorized();
    }

    public function test_global_defaults_match_owner_values_and_maturation_has_no_deadline(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/v1/lifecycle-settings')->assertOk()
            ->assertJsonPath('data.durations', ['sowing' => 3, 'germination' => 7, 'growth' => 21, 'flowering' => 56, 'hanging_drying' => 7, 'rack_drying' => 7, 'curing' => 14, 'flush' => 14])
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.canManage', true);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('maturation', $response->json('data.durations'));
        $this->assertDatabaseCount('strains', 0);
    }

    public function test_catalog_reads_are_shared_but_members_without_strain_permission_cannot_write(): void
    {
        $row = Strain::create(['name' => 'Shared Reference']);
        $member = User::factory()->create(['permission_grants' => array_values(array_diff(DelegatedPermission::values(), ['strains.manage', 'administration.view'])), 'location_access' => []]);
        $this->actingAs($member)->getJson('/api/v1/strains')->assertOk()->assertJsonPath('data.0.canManage', false);
        $this->getJson('/api/v1/strains/'.$row->id)->assertOk();
        $this->getJson('/api/v1/lifecycle-settings')->assertOk()->assertJsonPath('data.canManage', false);
        $this->postJson('/api/v1/strains', ['name' => 'Denied'])->assertForbidden();
        $this->patchJson('/api/v1/strains/'.$row->id, ['version' => 1, 'name' => 'Denied'])->assertForbidden();
        $this->patchJson('/api/v1/lifecycle-settings', [])->assertForbidden();
        $this->assertSame('Shared Reference', $row->fresh()->name);
    }

    public function test_create_preserves_descriptive_ranges_and_qualifiers_without_fetching_source(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/v1/strains', [
            'name' => '  Fictional Reference  ', 'source_url' => 'https://example.test/reference', 'classification' => 'hybrid',
            'genetics' => 'Source description', 'lineage' => 'A x (B x C)', 'thc' => '20–25%', 'cbd' => 'Less than 1%',
            'aroma' => 'Source aroma', 'reported_effects' => 'Reported description', 'notes' => 'Private catalog note',
            'duration_overrides' => ['flowering' => 70, 'flush' => 0, 'growth' => null],
            'sensor' => 'A', 'stock' => 100, 'id' => 'ignored',
        ])->assertCreated()->assertJsonPath('data.name', 'Fictional Reference')->assertJsonPath('data.thc', '20–25%')
            ->assertJsonPath('data.cbd', 'Less than 1%')->assertJsonPath('data.durationOverrides', ['flowering' => 70, 'flush' => 0])
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.canManage', true);
        $this->assertTrue((bool) preg_match('/^[0-9a-f-]{36}$/', $response->json('data.id')));
        $this->assertArrayNotHasKey('sensor', $response->json('data'));
        $this->assertArrayNotHasKey('stock', $response->json('data'));
        Http::assertNothingSent();
    }

    public function test_names_are_unique_case_insensitively_even_in_archives(): void
    {
        Strain::create(['name' => 'Catalog Name', 'is_archived' => true]);
        $this->actingAs($this->admin())->postJson('/api/v1/strains', ['name' => ' CATALOG NAME '])->assertUnprocessable()->assertJsonPath('error.code', 'catalog.errors.nameTaken');
    }

    public function test_patch_omission_retains_values_null_resets_override_and_noops_keep_version(): void
    {
        $row = Strain::create(['name' => 'Partial Update', 'thc' => 'Unknown', 'duration_overrides' => ['growth' => 28]]);
        $this->actingAs($this->admin())->patchJson('/api/v1/strains/'.$row->id, ['version' => 1, 'notes' => 'Added note'])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.thc', 'Unknown')->assertJsonPath('data.durationOverrides.growth', 28);
        $this->patchJson('/api/v1/strains/'.$row->id, ['version' => 2, 'notes' => 'Added note'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/strains/'.$row->id, ['version' => 2, 'duration_overrides' => ['growth' => null]])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertSame([], $row->fresh()->duration_overrides);
        $this->assertSame('Unknown', $row->fresh()->thc);
    }

    public function test_global_edit_changes_inheritance_without_rewriting_explicit_strain_overrides(): void
    {
        $inherited = Strain::create(['name' => 'Inherited']);
        $custom = Strain::create(['name' => 'Custom', 'duration_overrides' => ['growth' => 35]]);
        $durations = LifecycleSetting::findOrFail(1)->durations;
        $durations['growth'] = 28;
        $this->actingAs($this->admin())->patchJson('/api/v1/lifecycle-settings', ['version' => 1, 'durations' => $durations])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.durations.growth', 28);
        $this->assertSame(['growth' => 35], $custom->fresh()->duration_overrides);
        $this->assertNull($inherited->fresh()->duration_overrides);
        $this->assertSame(1, $custom->fresh()->version);
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => 2, 'durations' => array_reverse($durations, true)])->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_stale_versions_cannot_overwrite_catalog_or_global_changes(): void
    {
        $row = Strain::create(['name' => 'Original']);
        $this->actingAs($this->admin())->patchJson('/api/v1/strains/'.$row->id, ['version' => 1, 'name' => 'Changed'])->assertOk();
        $this->patchJson('/api/v1/strains/'.$row->id, ['version' => 1, 'name' => 'Stale'])->assertConflict()->assertJsonPath('error.code', 'catalog.errors.conflict');
        $durations = LifecycleSetting::findOrFail(1)->durations;
        $durations['sowing'] = 4;
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => 1, 'durations' => $durations])->assertOk();
        $durations['sowing'] = 5;
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => 1, 'durations' => $durations])->assertConflict();
        $this->assertSame(4, LifecycleSetting::findOrFail(1)->durations['sowing']);
        $this->assertSame('Changed', $row->fresh()->name);
    }

    public function test_invalid_metadata_and_unsafe_urls_are_rejected(): void
    {
        $this->actingAs($this->admin());
        foreach ([['name' => ' '], ['name' => str_repeat('x', 121)], ['source_url' => 'javascript:alert(1)'], ['source_url' => 'file:///etc/passwd'], ['classification' => 'invalid'], ['thc' => 23], ['notes' => str_repeat('x', 2001)], ['is_archived' => 'invalid']] as $extra) {
            $this->postJson('/api/v1/strains', ['name' => 'Invalid Fixture', ...$extra])->assertUnprocessable();
        }
        $this->assertDatabaseCount('strains', 0);
    }

    public function test_invalid_duration_shapes_unknown_keys_and_missing_versions_are_rejected(): void
    {
        $this->actingAs($this->admin());
        foreach ([null, '7', ['unknown' => 7], ['growth' => 0], ['growth' => 1.5], ['growth' => 3651], ['flush' => -1]] as $overrides) {
            $this->postJson('/api/v1/strains', ['name' => 'Invalid Durations', 'duration_overrides' => $overrides])->assertUnprocessable();
        }
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => 1, 'durations' => ['growth' => 7]])->assertUnprocessable();
        $this->patchJson('/api/v1/lifecycle-settings', ['version' => 1, 'durations' => null])->assertUnprocessable();
        $row = Strain::create(['name' => 'Versioned']);
        $this->patchJson('/api/v1/strains/'.$row->id, ['name' => 'Unversioned'])->assertUnprocessable();
    }

    public function test_archive_restore_filters_literal_search_and_pagination(): void
    {
        for ($i = 0; $i < 21; $i++) {
            Strain::create(['name' => sprintf('Catalog %02d', $i)]);
        }
        $row = Strain::create(['name' => 'Percent % reference']);
        $this->actingAs($this->admin())->getJson('/api/v1/strains')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 22);
        $this->getJson('/api/v1/strains?page=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/strains?search=%25')->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/strains/'.$row->id, ['version' => 1, 'is_archived' => true])->assertOk();
        $this->getJson('/api/v1/strains?status=archived')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/strains?status=all')->assertJsonPath('meta.total', 22);
        $this->patchJson('/api/v1/strains/'.$row->id, ['version' => 2, 'is_archived' => false])->assertOk()->assertJsonPath('data.version', 3);
    }

    public function test_sorting_uses_effective_flowering_before_pagination_and_validates_criteria(): void
    {
        for ($i = 0; $i < 21; $i++) {
            Strain::create(['name' => sprintf('Catalog %02d', $i)]);
        }
        $short = Strain::create(['name' => 'Short flowering', 'duration_overrides' => ['flowering' => 49]]);
        $long = Strain::create(['name' => 'Long flowering', 'duration_overrides' => ['flowering' => 70]]);
        $this->actingAs($this->admin())->getJson('/api/v1/strains?sort=flowering_asc')->assertOk()->assertJsonPath('data.0.id', $short->id);
        $this->getJson('/api/v1/strains?sort=flowering_desc')->assertOk()->assertJsonPath('data.0.id', $long->id);
        $this->getJson('/api/v1/strains?sort=flowering_asc&page=2')->assertJsonPath('data.2.id', $long->id);
        $this->getJson('/api/v1/strains?sort=name_desc')->assertJsonPath('data.0.id', $short->id);
        $this->getJson('/api/v1/strains?sort=invalid')->assertUnprocessable();
    }

    public function test_theoretical_yield_is_editable_nullable_and_sorts_unknown_values_last(): void
    {
        $unknown = Strain::create(['name' => 'Unknown yield']);
        $this->actingAs($this->admin());
        $low = $this->postJson('/api/v1/strains', ['name' => 'Low yield', 'theoretical_yield' => '450.50'])->assertCreated()->assertJsonPath('data.theoreticalYield', '450.50')->json('data.id');
        $high = $this->postJson('/api/v1/strains', ['name' => 'High yield', 'theoretical_yield' => '600'])->assertCreated()->assertJsonPath('data.theoreticalYield', '600.00')->json('data.id');
        $this->getJson('/api/v1/strains?sort=yield_desc')->assertOk()->assertJsonPath('data.0.id', $high)->assertJsonPath('data.2.id', $unknown->id);
        $this->getJson('/api/v1/strains?sort=yield_asc')->assertOk()->assertJsonPath('data.0.id', $low)->assertJsonPath('data.2.id', $unknown->id);
        $this->patchJson('/api/v1/strains/'.$low, ['version' => 1, 'notes' => 'Keep yield'])->assertOk()->assertJsonPath('data.theoreticalYield', '450.50');
        $this->patchJson('/api/v1/strains/'.$low, ['version' => 2, 'theoretical_yield' => null])->assertOk()->assertJsonPath('data.theoreticalYield', null);
        foreach ([-1, '600 g/m²', '1.234', 100000000] as $invalid) {
            $this->patchJson('/api/v1/strains/'.$high, ['version' => 1, 'theoretical_yield' => $invalid])->assertUnprocessable();
        }
        $this->assertSame('600.00', Strain::findOrFail($high)->theoretical_yield);
    }

    public function test_service_rechecks_revoked_administrator_and_inactive_actor(): void
    {
        $admin = $this->admin();
        $admin->fresh()->permissionRoles()->sync([AccessProfileSeeder::defaultProfile()->id]);
        try {
            app(StrainCatalog::class)->save($admin, ['name' => 'Denied stale admin']);
            $this->fail('Stale administrator accepted');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('strains', 0);
        }
        $admin = $this->admin();
        User::whereKey($admin->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        app(StrainCatalog::class)->saveSettings($admin, ['version' => 1, 'durations' => LifecycleSetting::findOrFail(1)->durations]);
    }

    public function test_audit_has_identifiers_and_field_names_without_catalog_content(): void
    {
        $actor = $this->admin();
        Log::spy();
        $row = app(StrainCatalog::class)->save($actor, ['name' => 'Do not log this value', 'notes' => 'Do not log these notes']);
        Log::shouldHaveReceived('notice')->with('strain.created', ['actor_uuid' => $actor->uuid, 'strain_uuid' => $row->id, 'fields' => ['name', 'notes']])->once();
        $this->assertSame(0, $actor->fresh()->session_version);
    }
}
