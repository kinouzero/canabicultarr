<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test']);
        Cache::flush();
        config(['queue.default' => 'database']);
        Mail::fake();
    }

    private function administrator(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function payload(?User $user = null): array
    {
        return [
            'name' => $user?->name ?? 'New Member',
            'email' => $user?->email ?? 'new-member@example.test',
            'permission_role_ids' => [$user?->permission_role_ids[0] ?? AccessProfileSeeder::defaultProfile()->id],
            'is_active' => $user?->is_active ?? true,
            ...($user ? [] : ['password' => 'A long test password 123!', 'password_confirmation' => 'A long test password 123!']),
        ];
    }

    public function test_profile_and_managed_users_expose_normalized_gravatar_urls(): void
    {
        $admin = $this->administrator();
        $admin->forceFill(['email' => ' Avatar@Example.Test '])->save();
        $expected = 'https://gravatar.com/avatar/6f72423ed6077061fb35f29ee6b05a8465e93a5c8bd55885c81fafd2280871ce?s=128&d=404&r=g';

        $this->actingAs($admin)->getJson('/api/v1/auth/user')
            ->assertOk()->assertJsonPath('data.avatarUrl', $expected);
        $this->getJson('/api/v1/users?search=Avatar')
            ->assertOk()->assertJsonPath('data.0.avatarUrl', $expected);

        $admin->forceFill(['email' => 'changed-avatar@example.test'])->save();
        $this->getJson('/api/v1/auth/user')->assertOk()
            ->assertJsonPath('data.avatarUrl', $admin->fresh()->avatarUrl());
        $this->assertNotSame($expected, $admin->avatarUrl());
    }

    public function test_guests_and_members_cannot_manage_accounts(): void
    {
        $target = User::factory()->create();
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->postJson('/api/v1/users', $this->payload())->assertUnauthorized();
        $this->actingAs($target)->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', $this->payload())->assertForbidden();
        $this->patchJson('/api/v1/users/'.$target->uuid, $this->payload($target))->assertForbidden();
    }

    public function test_administrator_can_list_search_and_paginate_without_private_fields(): void
    {
        $admin = $this->administrator();
        User::factory()->count(22)->create();
        $match = User::factory()->create(['name' => 'Literal _% Search', 'email' => 'needle@example.test']);
        $this->actingAs($admin)->getJson('/api/v1/users')
            ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 24)
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.session_version');
        $this->getJson('/api/v1/users?page=2')->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/users?search=NEEDLE')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->uuid);
        $this->getJson('/api/v1/users?search='.urlencode('_%'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/users?page=-1')->assertUnprocessable();
    }

    public function test_create_hashes_password_and_ignores_client_identifiers(): void
    {
        Log::spy();
        $admin = $this->administrator();
        $suppliedUuid = (string) Str::uuid();
        $response = $this->actingAs($admin)->postJson('/api/v1/users', [
            ...$this->payload(), 'email' => 'NEW-MEMBER@example.test', 'uuid' => $suppliedUuid, 'session_version' => 50,
        ])->assertCreated()->assertJsonPath('data.permissionRoles.0.name', 'Observer')->assertJsonPath('data.isActive', true);
        $user = User::where('email', 'new-member@example.test')->sole();
        $this->assertNotSame($suppliedUuid, $user->uuid);
        $this->assertSame($user->uuid, $response->json('data.id'));
        $this->assertSame(0, $user->session_version);
        $this->assertTrue(Hash::check($this->payload()['password'], $user->password));
        $response->assertJsonMissingPath('data.password');
        Log::shouldHaveReceived('notice')->with('user.created', [
            'actor_uuid' => $admin->uuid, 'user_uuid' => $user->uuid, 'profile_uuids' => [AccessProfileSeeder::defaultProfile()->id],
        ])->once();
    }

    public function test_duplicate_email_and_invalid_role_are_rejected(): void
    {
        $admin = $this->administrator();
        User::factory()->create(['email' => 'new-member@example.test']);
        $this->actingAs($admin)->postJson('/api/v1/users', $this->payload())
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'users.validation.emailTaken');
        $this->postJson('/api/v1/users', [...$this->payload(), 'email' => 'other@example.test', 'permission_role_ids' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors('permission_role_ids.0');
    }

    public function test_profile_only_update_preserves_password_and_sessions(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create();
        $previousHash = $target->password;
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$target->uuid, [
            ...$this->payload($target), 'name' => 'Updated Member',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Member');
        $this->assertSame($previousHash, $target->fresh()->password);
        $this->assertSame(0, $target->fresh()->session_version);
    }

    public function test_last_active_administrator_cannot_be_demoted(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$admin->uuid, [
            ...$this->payload($admin), 'permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id],
        ])->assertStatus(409)->assertJsonPath('error.code', 'users.errors.lastAdministrator');
        $this->assertTrue($admin->fresh()->isAdministrator());
        $this->assertSame(0, $admin->fresh()->session_version);
    }

    public function test_disabled_administrators_do_not_count_as_active_administrators(): void
    {
        $admin = $this->administrator();
        User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id], 'is_active' => false]);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$admin->uuid, [
            ...$this->payload($admin), 'permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id],
        ])->assertStatus(409);
    }

    public function test_self_deactivation_is_rejected_even_with_another_administrator(): void
    {
        $admin = $this->administrator();
        $this->administrator();
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$admin->uuid, [
            ...$this->payload($admin), 'is_active' => false,
        ])->assertStatus(409)->assertJsonPath('error.code', 'users.errors.cannotDisableSelf');
    }

    public function test_role_changes_require_reauthentication_of_the_changed_account(): void
    {
        $admin = $this->administrator();
        $target = $this->administrator();
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$target->uuid, [
            ...$this->payload($target), 'permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id],
        ])->assertOk();
        $this->assertSame(1, $target->fresh()->session_version);
        $this->actingAs($target->fresh())->withSession(['auth_version' => 0])->getJson('/api/v1/auth/user')->assertUnauthorized();
        $this->actingAs($target->fresh())->withSession(['auth_version' => 1])->getJson('/api/v1/admin/status')->assertForbidden();
    }

    public function test_deactivated_account_cannot_login_and_old_sessions_stay_revoked_after_reactivation(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create(['email' => 'inactive@example.test', 'password' => 'A long test password 123!']);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$target->uuid, [
            ...$this->payload($target), 'is_active' => false,
        ])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $target->email, 'password' => 'A long test password 123!'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'auth.errors.invalidCredentials');
        $this->actingAs($target->fresh())->getJson('/api/v1/auth/user')->assertUnauthorized();
        $this->actingAs($admin)->withSession(['auth_version' => 0])->patchJson('/api/v1/users/'.$target->uuid, $this->payload($target))->assertOk();
        $this->assertSame(2, $target->fresh()->session_version);
        $this->actingAs($target->fresh())->withSession(['auth_version' => 0])->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_password_reset_revokes_sessions_and_accepts_only_the_new_password(): void
    {
        Log::spy();
        $admin = $this->administrator();
        $target = User::factory()->create(['password' => 'An original password 123!']);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$target->uuid, [
            ...$this->payload($target), 'password' => 'A replacement password 456!', 'password_confirmation' => 'A replacement password 456!',
        ])->assertOk();
        $this->assertTrue(Hash::check('A replacement password 456!', $target->fresh()->password));
        $this->assertFalse(Hash::check('An original password 123!', $target->fresh()->password));
        $this->assertSame(1, $target->fresh()->session_version);
        Log::shouldHaveReceived('notice')->with('user.updated', [
            'actor_uuid' => $admin->uuid, 'user_uuid' => $target->uuid, 'fields' => ['password'],
        ])->once();
        $this->actingAs($target->fresh())->withSession(['auth_version' => 0])->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_changing_email_preserves_sessions_and_verification_until_confirmation(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create();
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$target->uuid, [
            ...$this->payload($target), 'email' => 'changed@example.test',
        ])->assertOk();
        $this->assertEquals($target->email_verified_at, $target->fresh()->email_verified_at);
        $this->assertSame($target->email, $target->fresh()->email);
        $this->assertDatabaseHas('pending_email_changes', ['user_id' => $target->id, 'email' => 'changed@example.test']);
        $this->assertSame(0, $target->fresh()->session_version);
    }

    public function test_database_sessions_are_removed_on_sensitive_changes(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'test-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => now()->timestamp,
        ]);
        config(['session.driver' => 'database']);
        app(UserAdministration::class)->update($admin, $target, [...$this->payload($target), 'is_active' => false]);
        $this->assertDatabaseMissing('sessions', ['id' => 'test-session']);
    }

    public function test_legacy_version_zero_session_remains_valid_until_a_sensitive_change(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin)->getJson('/api/v1/auth/user')->assertOk()->assertSessionHas('auth_version', 0);
    }

    public function test_revoked_actor_is_rechecked_inside_the_administrative_transaction(): void
    {
        $admin = $this->administrator();
        $this->administrator();
        $staleActor = $admin->replicate()->setRawAttributes($admin->getAttributes(), true);
        $admin->fresh()->permissionRoles()->sync([AccessProfileSeeder::defaultProfile()->id]);
        $this->actingAs($staleActor)->postJson('/api/v1/users', $this->payload())->assertForbidden();
    }
}
