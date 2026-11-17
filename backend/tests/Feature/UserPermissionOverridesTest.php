<?php

namespace Tests\Feature;

use App\Enums\DelegatedPermission;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionOverridesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function input(User $user, array $extra = []): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'is_active' => true, 'permission_role_ids' => $user->permission_role_ids, ...$extra];
    }

    public function test_denials_override_profile_permissions_and_allowances_add_missing_permissions(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('grower')->id]]);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$user->uuid, $this->input($user, ['permission_grants' => ['inventory.manage'], 'permission_denials' => ['plants.manage']]))
            ->assertOk()->assertJsonPath('data.permissions', ['inventory.manage'])->assertJsonPath('data.permissionDenials', ['plants.manage'])->assertJsonMissingPath('data.role');
        $user->refresh();
        $this->assertFalse($user->hasOperationalPermission(DelegatedPermission::Plants));
        $this->assertTrue($user->hasOperationalPermission(DelegatedPermission::Inventory));
        $this->assertSame(1, $user->session_version);
        $this->actingAs($user)->withSession(['auth_version' => $user->session_version])->postJson('/api/v1/plants', [])->assertForbidden();
        $this->getJson('/api/v1/auth/user')->assertJsonPath('data.permissions', ['inventory.manage'])->assertJsonMissingPath('data.role');
    }

    public function test_administrator_profile_does_not_bypass_individual_denials(): void
    {
        $user = $this->admin();
        $user->forceFill(['permission_denials' => ['plants.manage']])->save();
        $this->assertTrue($user->isAdministrator());
        $this->actingAs($user)->getJson('/api/v1/users')->assertOk();
        $this->postJson('/api/v1/plants', [])->assertForbidden();
    }

    public function test_removing_last_administrator_via_override_or_profile_edit_is_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$admin->uuid, $this->input($admin, ['permission_denials' => ['administration.view']]))->assertConflict()->assertJsonPath('error.code', 'users.errors.lastAdministrator');
        $profile = $admin->permissionRoles->first();
        $this->patchJson('/api/v1/permission-roles/'.$profile->id, ['name' => $profile->name, 'description' => null, 'permissions' => ['plants.manage'], 'version' => $profile->version])->assertConflict()->assertJsonPath('error.code', 'users.errors.lastAdministrator');
        $this->assertTrue($admin->fresh()->isAdministrator());
        $this->assertSame(0, $admin->fresh()->session_version);
    }

    public function test_administrative_allowance_counts_toward_last_administrator_protection(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['permission_grants' => ['administration.view']]);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$admin->uuid, $this->input($admin, ['permission_denials' => ['administration.view']]))->assertOk();
        $this->assertSame(1, User::withPermission('administration.view')->where('is_active', true)->count());
        $this->assertTrue($other->isAdministrator());
    }

    public function test_conflicting_allow_and_deny_values_and_missing_profiles_are_rejected(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$user->uuid, $this->input($user, ['permission_grants' => ['plants.manage'], 'permission_denials' => ['plants.manage']]))->assertUnprocessable()->assertJsonValidationErrors('permission_denials');
        $this->patchJson('/api/v1/users/'.$user->uuid, $this->input($user, ['permission_role_ids' => null]))->assertUnprocessable()->assertJsonValidationErrors('permission_role_ids');
        $this->assertSame(0, $user->fresh()->session_version);
    }
}
