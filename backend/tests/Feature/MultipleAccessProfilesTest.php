<?php

namespace Tests\Feature;

use App\Models\PermissionRole;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MultipleAccessProfilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function administrator(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function input(User $user, array $fields = []): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'is_active' => true, 'permission_role_ids' => $user->permission_role_ids, ...$fields];
    }

    public function test_profile_union_and_individual_denials_match_server_queries(): void
    {
        $grower = AccessProfileSeeder::defaultProfile('grower');
        $technician = AccessProfileSeeder::defaultProfile('technician');
        $user = User::factory()->create(['permission_role_ids' => [$grower->id, $technician->id], 'permission_grants' => ['inventory.manage'], 'permission_denials' => ['plants.manage', 'assets.manage']]);
        $expected = array_values(array_diff(array_unique([...$grower->grants(), ...$technician->grants(), 'inventory.manage']), ['plants.manage', 'assets.manage']));
        sort($expected);
        $this->assertSame($expected, $user->effectiveOperationalPermissions());
        $this->assertTrue(User::withPermission('inventory.manage')->whereKey($user->id)->exists());
        $this->assertFalse(User::withPermission('plants.manage')->whereKey($user->id)->exists());
        $this->assertSame(1, User::withPermission('locations.manage')->whereKey($user->id)->count());
    }

    public function test_assignments_are_set_based_revoke_once_and_validate_each_profile(): void
    {
        $admin = $this->administrator();
        $ids = [AccessProfileSeeder::defaultProfile('grower')->id, AccessProfileSeeder::defaultProfile('technician')->id];
        sort($ids);
        $user = User::factory()->create(['permission_role_ids' => $ids]);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$user->uuid, $this->input($user, ['permission_role_ids' => array_reverse($ids)]))->assertOk();
        $this->assertSame(0, $user->fresh()->session_version);
        $this->patchJson('/api/v1/users/'.$user->uuid, $this->input($user, ['permission_role_ids' => [$ids[0]]]))->assertOk()->assertJsonCount(1, 'data.permissionRoles');
        $this->assertSame(1, $user->fresh()->session_version);
        foreach ([[], [$ids[0], $ids[0]], [$ids[0], fake()->uuid()]] as $invalid) {
            $this->patchJson('/api/v1/users/'.$user->uuid, $this->input($user->fresh(), ['permission_role_ids' => $invalid]))->assertUnprocessable();
            $this->assertSame([$ids[0]], $user->fresh()->permission_role_ids);
        }
    }

    public function test_last_administrator_is_protected_across_multiple_profiles_and_denials(): void
    {
        $first = PermissionRole::create(['name' => 'First admin profile', 'permissions' => ['administration.view']]);
        $second = PermissionRole::create(['name' => 'Second admin profile', 'permissions' => ['administration.view']]);
        $user = User::factory()->create(['permission_role_ids' => [$first->id, $second->id]]);
        $this->actingAs($user)->patchJson('/api/v1/users/'.$user->uuid, $this->input($user, ['permission_denials' => ['administration.view']]))->assertConflict();
        $this->assertTrue($user->fresh()->isAdministrator());
        $this->patchJson('/api/v1/users/'.$user->uuid, $this->input($user, ['permission_role_ids' => [$second->id]]))->assertOk();
        $this->assertTrue($user->fresh()->isAdministrator());
        $this->assertSame(1, $user->fresh()->session_version);
    }

    public function test_shared_profile_demotion_respects_other_profiles_and_invalidates_each_assignee_once(): void
    {
        $first = PermissionRole::create(['name' => 'First admin profile', 'permissions' => ['administration.view']]);
        $second = PermissionRole::create(['name' => 'Second admin profile', 'permissions' => ['administration.view']]);
        $user = User::factory()->create(['permission_role_ids' => [$first->id, $second->id]]);
        $other = User::factory()->create(['permission_role_ids' => [$first->id, AccessProfileSeeder::defaultProfile()->id]]);
        $data = fn ($profile) => ['version' => $profile->version, 'name' => $profile->name, 'permissions' => []];
        $this->actingAs($user)->patchJson('/api/v1/permission-roles/'.$first->id, $data($first))->assertOk();
        $this->assertTrue($user->fresh()->isAdministrator());
        $this->assertFalse($other->fresh()->isAdministrator());
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertSame(1, $other->fresh()->session_version);
        $this->actingAs($user->fresh())->withSession(['auth_version' => 1])->patchJson('/api/v1/permission-roles/'.$second->id, $data($second))->assertConflict();
        $this->assertTrue($user->fresh()->isAdministrator());
    }

    public function test_profile_filter_matches_any_assigned_profile_without_duplicate_users(): void
    {
        $admin = $this->administrator();
        $grower = AccessProfileSeeder::defaultProfile('grower');
        $user = User::factory()->create(['permission_role_ids' => [$grower->id, AccessProfileSeeder::defaultProfile('technician')->id]]);
        $this->actingAs($admin)->getJson('/api/v1/users?permission_role_id='.$grower->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $user->uuid)->assertJsonCount(2, 'data.0.permissionRoles');
    }
}
