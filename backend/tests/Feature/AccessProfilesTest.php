<?php

namespace Tests\Feature;

use App\Enums\DelegatedPermission;
use App\Models\PermissionRole;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\PermissionRoleAdministration;
use App\Services\UserAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccessProfilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        config(['app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test']);
        PermissionRole::query()->delete();
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function profile(array $extra = []): PermissionRole
    {
        return PermissionRole::create(['name' => 'Fictional Operations', 'permissions' => ['assets.manage'], ...$extra]);
    }

    private function input(PermissionRole $role, array $extra = []): array
    {
        return ['name' => $role->name, 'description' => $role->description, 'permissions' => $role->grants(), 'is_archived' => $role->is_archived, 'version' => $role->version, ...$extra];
    }

    private function member(PermissionRole $profile, array $extra = []): User
    {
        return User::factory()->create(['permission_role_ids' => [$profile->id], ...$extra]);
    }

    private function userInput(User $user, array $extra = []): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'permission_role_ids' => $user->permission_role_ids, 'is_active' => $user->is_active, ...$extra];
    }

    public function test_guests_and_every_kind_of_member_cannot_administer_profiles(): void
    {
        $profile = $this->profile();
        $this->getJson('/api/v1/permission-roles')->assertUnauthorized();
        $this->postJson('/api/v1/permission-roles', $this->input($profile))->assertUnauthorized();
        $member = $this->member($profile, ['permission_grants' => array_values(array_diff(DelegatedPermission::values(), ['administration.view']))]);
        $this->actingAs($member)->getJson('/api/v1/permission-roles')->assertForbidden();
        $this->postJson('/api/v1/permission-roles', $this->input($profile))->assertForbidden();
        $this->patchJson('/api/v1/permission-roles/'.$profile->id, $this->input($profile))->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->patchJson('/api/v1/notifications/settings', [])->assertForbidden();
    }

    public function test_admin_creates_a_canonical_profile_and_lists_private_metadata_without_users(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/api/v1/permission-roles', ['name' => '  Fictional Operators  ', 'description' => 'Fictional description', 'permissions' => ['sensors.manage', 'assets.manage'], 'id' => 'ignored', 'version' => 99])->assertCreated()->assertJsonPath('data.name', 'Fictional Operators')->assertJsonPath('data.permissions', ['assets.manage', 'sensors.manage'])->assertJsonPath('data.version', 1)->assertJsonPath('data.assignedUsers', 0);
        $profile = PermissionRole::findOrFail($response->json('data.id'));
        $member = $this->member($profile);
        $list = $this->getJson('/api/v1/permission-roles')->assertOk()->assertJsonPath('data.0.assignedUsers', 1);
        $this->assertStringContainsString('no-store', $list->headers->get('Cache-Control'));
        foreach ([$member->email, $member->uuid, $member->password] as $value) {
            $this->assertStringNotContainsString($value, $list->getContent());
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_unknown_reserved_duplicate_or_malformed_permissions_are_denied(): void
    {
        $admin = $this->admin();
        foreach ([['unknown.manage'], ['notifications.manage'], ['assets.manage', 'assets.manage'], ['x' => 'assets.manage'], null, 'assets.manage', array_fill(0, 7, 'assets.manage')] as $permissions) {
            $this->actingAs($admin)->postJson('/api/v1/permission-roles', ['name' => 'Invalid Profile', 'permissions' => $permissions])->assertUnprocessable();
        }
        $this->assertDatabaseCount('permission_roles', 1);
    }

    public function test_blank_names_invalid_state_and_oversized_metadata_are_denied(): void
    {
        $admin = $this->admin();
        foreach ([['name' => '  '], ['name' => str_repeat('x', 121)], ['description' => str_repeat('x', 2001)], ['is_archived' => 'invalid']] as $extra) {
            $this->actingAs($admin)->postJson('/api/v1/permission-roles', ['name' => 'Valid Name', 'permissions' => [], ...$extra])->assertUnprocessable();
        }
        $this->assertDatabaseCount('permission_roles', 1);
    }

    public function test_profile_names_are_unique_case_insensitively_including_archives(): void
    {
        $profile = $this->profile(['is_archived' => true]);
        $this->actingAs($this->admin())->postJson('/api/v1/permission-roles', ['name' => strtoupper($profile->name), 'permissions' => []])->assertUnprocessable()->assertJsonPath('error.code', 'accessProfiles.errors.nameTaken');
    }

    public function test_listing_filters_literal_search_archive_state_pagination_and_sorting(): void
    {
        for ($i = 0; $i < 23; $i++) {
            $this->profile(['name' => sprintf('Profile %02d', $i)]);
        }
        $this->profile(['name' => 'Percent % Profile']);
        $this->profile(['name' => 'Archived Profile', 'is_archived' => true]);
        $this->actingAs($this->admin())->getJson('/api/v1/permission-roles')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 25);
        $this->getJson('/api/v1/permission-roles?page=2')->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/permission-roles?search=%25')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Percent % Profile');
        $this->getJson('/api/v1/permission-roles?status=archived')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/permission-roles?status=all')->assertJsonPath('meta.total', 26);
    }

    public function test_assignment_combines_profile_and_direct_grants_without_admin_authority(): void
    {
        $profile = $this->profile();
        $member = $this->member($profile, ['permission_grants' => ['inventory.manage', 'assets.manage']]);
        $this->actingAs($member)->getJson('/api/v1/auth/user')->assertOk()->assertJsonPath('data.permissions', ['assets.manage', 'inventory.manage'])->assertJsonPath('data.permissionRoles.0.name', $profile->name);
        $this->postJson('/api/v1/assets', ['name' => 'Profile Device', 'status' => 'active'])->assertCreated();
        $this->postJson('/api/v1/inventory/items', ['name' => 'Profile Stock', 'unit' => 'kg'])->assertCreated();
        $this->postJson('/api/v1/sites', [])->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_assigning_removing_and_switching_profiles_revokes_sessions_and_preserves_direct_grants(): void
    {
        $admin = $this->admin();
        $one = $this->profile();
        $two = $this->profile(['name' => 'Second Profile', 'permissions' => ['sensors.manage']]);
        $member = User::factory()->create(['permission_grants' => ['inventory.manage']]);
        foreach ([$one->id, $two->id, AccessProfileSeeder::defaultProfile()->id] as $index => $id) {
            $this->actingAs($admin)->patchJson('/api/v1/users/'.$member->uuid, $this->userInput($member, ['permission_role_ids' => [$id]]))->assertOk();
            $member->refresh();
            $this->assertSame($id, $member->permission_role_ids[0]);
            $this->assertSame($index + 1, $member->session_version);
            $this->assertSame(['inventory.manage'], $member->delegatedPermissions());
        }
    }

    public function test_omitted_or_same_assignment_preserves_profile_and_session_version(): void
    {
        $admin = $this->admin();
        $profile = $this->profile();
        $member = $this->member($profile);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$member->uuid, $this->userInput($member, ['name' => 'Renamed Member']))->assertOk();
        $this->patchJson('/api/v1/users/'.$member->uuid, $this->userInput($member->fresh(), ['permission_role_ids' => [$profile->id], 'permission_grants' => []]))->assertOk();
        $this->assertSame(0, $member->fresh()->session_version);
        $this->assertSame($profile->id, $member->fresh()->permission_role_ids[0]);
        app(UserAdministration::class)->update($admin, $member, $this->userInput($member->fresh(), ['permission_grants' => ['inventory.manage']]));
        $this->assertSame($profile->id, $member->fresh()->permission_role_ids[0]);
    }

    public function test_unknown_archived_or_malformed_assignment_is_rejected(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $archived = $this->profile(['is_archived' => true]);
        foreach ([$archived->id, '00000000-0000-4000-8000-000000000001', 'invalid'] as $id) {
            $this->actingAs($admin)->patchJson('/api/v1/users/'.$member->uuid, $this->userInput($member, ['permission_role_ids' => [$id]]))->assertUnprocessable();
        }
        $this->assertSame($member->permission_role_ids[0], $member->fresh()->permission_role_ids[0]);
        $this->assertSame(0, $member->fresh()->session_version);
    }

    public function test_create_assignment_and_promotion_do_not_leave_dormant_admin_profiles(): void
    {
        $admin = $this->admin();
        $profile = $this->profile();
        $response = $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Profile Member', 'email' => 'profile-member@example.test', 'password' => 'Fictional profile password 123!', 'password_confirmation' => 'Fictional profile password 123!', 'permission_role_ids' => [$profile->id]])->assertCreated()->assertJsonPath('data.permissionRoles.0.id', $profile->id);
        $member = User::where('uuid', $response->json('data.id'))->sole();
        $this->patchJson('/api/v1/users/'.$member->uuid, $this->userInput($member, ['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]))->assertOk()->assertJsonPath('data.permissionRoles.0.name', 'Administrator');
        $this->assertTrue($member->fresh()->isAdministrator());
        $this->assertSame(0, $profile->users()->count());
    }

    public function test_permission_edit_revokes_every_assigned_account_and_only_their_security_records(): void
    {
        $admin = $this->admin();
        $profile = $this->profile();
        $first = $this->member($profile, ['permission_grants' => ['inventory.manage']]);
        $inactive = $this->member($profile, ['is_active' => false]);
        $other = User::factory()->create();
        foreach ([$first, $inactive, $other] as $owner) {
            $owner->remember_token = 'fictional-profile-remember';
            $owner->save();
            DB::table('sessions')->insert(['id' => 'profile-session-'.$owner->id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp]);
            Password::broker()->createToken($owner);
            $endpoint = 'https://fcm.googleapis.com/fcm/send/fictional-profile-'.$owner->id;
            PushSubscription::create(['user_id' => $owner->id, 'endpoint_hash' => hash('sha256', $endpoint), 'session_hash' => hash('sha256', 'fictional-session'), 'endpoint' => $endpoint, 'keys' => ['p256dh' => 'fictional-key', 'auth' => 'fictional-auth'], 'vapid_key_hash' => hash('sha256', 'fictional-vapid'), 'user_version' => 0]);
        }
        $hash = $first->password;
        config(['session.driver' => 'database']);
        $this->actingAs($admin)->patchJson('/api/v1/permission-roles/'.$profile->id, $this->input($profile, ['permissions' => ['sensors.manage']]))->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.assignedUsers', 2);
        foreach ([$first, $inactive] as $member) {
            $this->assertSame(1, $member->fresh()->session_version);
            $this->assertNull($member->fresh()->remember_token);
            $this->assertDatabaseMissing('sessions', ['user_id' => $member->id]);
            $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $member->id]);
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $member->email]);
        }
        $this->assertSame(['inventory.manage', 'sensors.manage'], $first->fresh()->effectiveOperationalPermissions());
        $this->assertSame($hash, $first->fresh()->password);
        $this->assertFalse($first->fresh()->isAdministrator());
        foreach (['sessions', 'push_subscriptions'] as $table) {
            $this->assertDatabaseHas($table, ['user_id' => $other->id]);
        }
        $this->assertSame(0, $other->fresh()->session_version);
        $this->assertSame(0, $admin->fresh()->session_version);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_renaming_or_noop_profile_edits_do_not_close_sessions(): void
    {
        $admin = $this->admin();
        $profile = $this->profile(['permissions' => ['assets.manage', 'inventory.manage']]);
        $member = $this->member($profile);
        $this->actingAs($admin)->patchJson('/api/v1/permission-roles/'.$profile->id, $this->input($profile, ['permissions' => ['inventory.manage', 'assets.manage']]))->assertJsonPath('data.version', 1);
        $this->patchJson('/api/v1/permission-roles/'.$profile->id, $this->input($profile, ['name' => 'Renamed Profile']))->assertJsonPath('data.version', 2);
        $this->assertSame(0, $member->fresh()->session_version);
    }

    public function test_stale_profile_version_is_rejected_before_member_revocation(): void
    {
        $admin = $this->admin();
        $profile = $this->profile();
        $member = $this->member($profile);
        $this->actingAs($admin)->patchJson('/api/v1/permission-roles/'.$profile->id, $this->input($profile, ['name' => 'Renamed Profile']))->assertOk();
        $this->patchJson('/api/v1/permission-roles/'.$profile->id, $this->input($profile, ['permissions' => []]))->assertStatus(409)->assertJsonPath('error.code', 'accessProfiles.errors.conflict');
        $this->assertSame(0, $member->fresh()->session_version);
        $this->assertSame(['assets.manage'], $profile->fresh()->grants());
    }

    public function test_assigned_profiles_cannot_be_archived_even_if_accounts_are_inactive(): void
    {
        $profile = $this->profile();
        $member = $this->member($profile, ['is_active' => false]);
        $this->actingAs($this->admin())->patchJson('/api/v1/permission-roles/'.$profile->id, $this->input($profile, ['is_archived' => true]))->assertStatus(409)->assertJsonPath('error.code', 'accessProfiles.errors.assigned');
        $this->assertFalse($profile->fresh()->is_archived);
        $this->assertSame(0, $member->fresh()->session_version);
    }

    public function test_unassigned_profiles_archive_restore_and_keep_names_reserved(): void
    {
        $profile = $this->profile();
        $admin = $this->admin();
        $this->actingAs($admin)->patchJson('/api/v1/permission-roles/'.$profile->id, $this->input($profile, ['is_archived' => true]))->assertOk()->assertJsonPath('data.isArchived', true);
        $this->getJson('/api/v1/permission-roles')->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/permission-roles/'.$profile->id, $this->input($profile->fresh(), ['is_archived' => false]))->assertOk()->assertJsonPath('data.version', 3);
    }

    public function test_removed_profile_right_is_rechecked_inside_an_operational_write(): void
    {
        $profile = $this->profile();
        $member = $this->member($profile);
        $member->load('permissionRoles');
        app(PermissionRoleAdministration::class)->save($this->admin(), $this->input($profile, ['permissions' => []]), $profile);
        try {
            app(AssetAdministration::class)->save($member, ['name' => 'Denied Cached Profile']);
            $this->fail('Cached profile rights accepted.');
        } catch (AuthenticationException|AuthorizationException) {
            $this->assertDatabaseCount('assets', 0);
        }
    }

    public function test_direct_permissions_can_retain_access_after_the_profile_loses_that_right(): void
    {
        $profile = $this->profile();
        $member = $this->member($profile, ['permission_grants' => ['assets.manage']]);
        app(PermissionRoleAdministration::class)->save($this->admin(), $this->input($profile, ['permissions' => []]), $profile);
        $this->assertTrue($member->fresh()->hasOperationalPermission(DelegatedPermission::Assets));
        $this->assertSame(1, $member->fresh()->session_version);
    }

    public function test_revoked_or_inactive_administrators_cannot_update_profiles_through_services(): void
    {
        $admin = $this->admin();
        $profile = $this->profile();
        $admin->fresh()->permissionRoles()->sync([AccessProfileSeeder::defaultProfile()->id]);
        try {
            app(PermissionRoleAdministration::class)->save($admin, $this->input($profile, ['permissions' => []]), $profile);
            $this->fail('Stale administrator accepted.');
        } catch (AuthenticationException|AuthorizationException) {
            $this->assertSame(1, $profile->fresh()->version);
        }
        $this->assertFalse(Gate::forUser($admin->fresh())->allows('viewAny', PermissionRole::class));
    }

    public function test_audit_uses_identifiers_field_names_and_affected_count_without_content(): void
    {
        $admin = $this->admin();
        $profile = $this->profile();
        $this->member($profile);
        Log::spy();
        app(PermissionRoleAdministration::class)->save($admin, $this->input($profile, ['permissions' => []]), $profile);
        Log::shouldHaveReceived('notice')->with('access_profile.updated', ['actor_uuid' => $admin->uuid, 'profile_uuid' => $profile->id, 'fields' => ['permissions'], 'affected_accounts' => 1])->once();
    }
}
