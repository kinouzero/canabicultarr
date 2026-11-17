<?php

namespace Tests\Feature;

use App\Enums\DelegatedPermission;
use App\Models\PermissionRole;
use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\PermissionRoleAdministration;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class DefaultAccessProfilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_useful_independent_profiles_without_account_administration(): void
    {
        $this->assertDatabaseCount('permission_roles', 7);
        $this->assertDatabaseCount('users', 0);
        foreach (AccessProfileSeeder::PROFILES as $input) {
            $profile = PermissionRole::where('name', $input['name'])->sole();
            $member = User::factory()->create(['permission_role_ids' => [$profile->id]]);
            $this->assertSame(in_array('plants.manage', $input['permissions']), Gate::forUser($member)->allows('create', Plant::class));
            $this->assertSame(in_array('strains.manage', $input['permissions']), Gate::forUser($member)->allows('create', Strain::class));
            $this->assertSame(in_array('administration.view', $input['permissions']), Gate::forUser($member)->allows('create', User::class));
            $this->assertSame(in_array('administration.view', $input['permissions']), Gate::forUser($member)->allows('lifecycle.manage'));
            $this->assertSame([], array_diff($profile->grants(), DelegatedPermission::values()));
        }
    }

    public function test_seeding_again_preserves_customization_archives_assignments_and_sessions(): void
    {
        $profile = PermissionRole::where('name', 'Grower')->sole();
        $profile->update(['name' => 'Custom cultivation team', 'permissions' => ['inventory.manage'], 'description' => 'Customized description']);
        $observer = PermissionRole::where('name', 'Observer')->sole();
        $observer->update(['is_archived' => true]);
        $member = User::factory()->create(['permission_role_ids' => [$profile->id]]);
        $before = $member->fresh()->getAttributes();
        $this->seed(AccessProfileSeeder::class);
        $this->seed(AccessProfileSeeder::class);
        $this->assertDatabaseCount('permission_roles', 7);
        $this->assertSame('Custom cultivation team', $profile->fresh()->name);
        $this->assertSame(['inventory.manage'], $profile->fresh()->grants());
        $this->assertTrue($observer->fresh()->is_archived);
        $this->assertSame($before, $member->fresh()->getAttributes());
    }

    public function test_grower_tracks_plants_and_care_only_in_allowed_locations(): void
    {
        $site = Site::create(['name' => 'Allowed site']);
        $outside = Site::create(['name' => 'Outside site']);
        $strain = Strain::create(['name' => 'Fictional strain']);
        $grower = User::factory()->create(['permission_role_ids' => [PermissionRole::where('name', 'Grower')->sole()->id], 'location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $input = ['name' => 'Fictional plant', 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id], 'request_id' => (string) Str::uuid(), 'planned' => true];
        $this->actingAs($grower);
        $created = $this->postJson('/api/v1/plants', $input)->assertCreated()->assertJsonPath('data.canManage', true);
        $id = $created->json('data.id');
        $this->postJson('/api/v1/plants/'.$id.'/actions', ['action' => 'sow', 'version' => 1, 'request_id' => (string) Str::uuid()])->assertOk();
        $plant = Plant::findOrFail($id);
        $plant->forceFill(['phase' => 'growth'])->save();
        $this->postJson('/api/v1/plants/'.$id.'/waterings', ['with_products' => false, 'version' => $plant->version, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/v1/plants/'.$id.'/maintenance', ['type' => 'pruning', 'version' => $plant->fresh()->version, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/v1/plants', [...$input, 'request_id' => (string) Str::uuid(), 'location' => ['type' => 'site', 'id' => $outside->id]])->assertNotFound();
        $this->postJson('/api/v1/strains', ['name' => 'Denied'])->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->assertDatabaseCount('plants', 1);
    }

    public function test_strain_permission_is_independent_and_global_cycle_settings_stay_admin_only(): void
    {
        $member = User::factory()->create(['permission_grants' => ['strains.manage']]);
        $this->actingAs($member);
        $response = $this->postJson('/api/v1/strains', ['name' => 'Fictional strain', 'duration_overrides' => ['flowering' => 63]])->assertCreated()->assertJsonPath('data.canManage', true);
        $this->patchJson('/api/v1/strains/'.$response->json('data.id'), ['name' => 'Updated strain', 'version' => 1])->assertOk();
        $this->postJson('/api/v1/plants', [])->assertForbidden();
        $this->patchJson('/api/v1/lifecycle-settings', [])->assertForbidden();
    }

    public function test_profile_revocation_rejects_stale_cultivation_actor(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $profile = PermissionRole::where('name', 'Grower')->sole();
        $grower = User::factory()->create(['permission_role_ids' => [$profile->id]]);
        app(PermissionRoleAdministration::class)->save($admin, ['name' => $profile->name, 'permissions' => [], 'version' => $profile->version], $profile);
        $this->assertSame(1, $grower->fresh()->session_version);
        $site = Site::create(['name' => 'Fictional site']);
        $strain = Strain::create(['name' => 'Fictional strain']);
        $this->expectException(AuthenticationException::class);
        app(PlantTracking::class)->create($grower, ['name' => 'Denied', 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id], 'request_id' => (string) Str::uuid()]);
    }

    public function test_user_filters_and_effective_permission_preview_are_private_and_accurate(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $profile = PermissionRole::where('name', 'Grower')->sole();
        $member = User::factory()->create(['permission_role_ids' => [$profile->id], 'permission_grants' => ['inventory.manage']]);
        User::factory()->create(['is_active' => false]);
        $this->actingAs($admin)->getJson('/api/v1/users?role=member&status=active&permission_role_id='.$profile->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $member->uuid)->assertJsonPath('data.0.permissions', ['inventory.manage', 'plants.manage'])->assertJsonPath('data.0.permissionRoles.0.permissions', ['plants.manage'])->assertJsonPath('summary', ['total' => 3, 'active' => 2, 'inactive' => 1, 'administrators' => 1]);
        $this->getJson('/api/v1/users?status=invalid')->assertUnprocessable();
        $this->actingAs($member)->getJson('/api/v1/users')->assertForbidden();
    }
}
