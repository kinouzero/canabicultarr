<?php

namespace Tests\Feature;

use App\Models\OidcIdentity;
use App\Models\User;
use App\Services\AccountProfile;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['queue.default' => 'database', 'app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test']);
        Mail::fake();
    }

    public function test_profile_updates_require_an_active_current_session(): void
    {
        $data = ['name' => 'New Name', 'email' => 'new@example.test'];
        $this->patchJson('/api/v1/account/profile', $data)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_active' => false]))->patchJson('/api/v1/account/profile', $data)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['session_version' => 1]))->withSession(['auth_version' => 0])->patchJson('/api/v1/account/profile', $data)->assertUnauthorized();
    }

    public function test_own_profile_is_normalized_without_changing_privileges_sessions_or_oidc_links(): void
    {
        config(['oidc.issuer' => 'https://identity.example.test']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $original = [...$user->fresh()->getAttributes(), 'permission_role_ids' => $user->permission_role_ids];
        $identity = OidcIdentity::create(['user_id' => $user->id, 'issuer' => 'https://identity.example.test', 'subject' => 'fictional-subject']);
        foreach ([$user->email, 'new@example.test', 'other@example.test'] as $email) {
            DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'fictional-token', 'created_at' => now()]);
        }
        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => '  New Name  ', 'email' => '  New@Example.test  ', 'id' => $other->uuid, 'permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id], 'is_active' => false, 'password' => 'ignored'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id', $user->uuid)->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.email', $user->email)->assertJsonPath('data.pendingEmail', 'new@example.test')
            ->assertJsonPath('data.permissionRoles.0.name', 'Observer')->assertJsonPath('data.oidcLinked', true)->assertJsonMissingPath('data.password');
        $fresh = $user->fresh();
        foreach (['permission_role_ids', 'password', 'session_version', 'remember_token', 'is_active'] as $field) {
            $this->assertSame($original[$field], ($field === 'permission_role_ids' ? $fresh->permission_role_ids : $fresh->getAttributes()[$field]));
        }
        $this->assertEquals($user->email_verified_at, $fresh->email_verified_at);
        $this->assertSame($other->email, $other->fresh()->email);
        $this->assertDatabaseHas('oidc_identities', ['id' => $identity->id, 'user_id' => $user->id, 'subject' => 'fictional-subject']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $original['email']]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'new@example.test']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'other@example.test']);
        $this->actingAs($fresh)->withSession(['auth_version' => $original['session_version']])->getJson('/api/v1/auth/user')->assertOk()->assertJsonPath('data.name', 'New Name');
    }

    public function test_validation_and_unchanged_email_preserve_account_verification(): void
    {
        $user = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $other = User::factory()->create();
        $this->actingAs($user);
        foreach ([['name' => ''], ['name' => str_repeat('a', 121)], ['email' => 'invalid'], ['email' => strtoupper($other->email)]] as $invalid) {
            $this->patchJson('/api/v1/account/profile', ['name' => 'New Name', 'email' => $user->email, ...$invalid])->assertUnprocessable();
        }
        $this->assertSame($user->name, $user->fresh()->name);
        $verified = $user->email_verified_at->toISOString();
        $this->patchJson('/api/v1/account/profile', ['name' => 'New Name', 'email' => $user->email])->assertOk();
        $this->assertSame($verified, $user->fresh()->email_verified_at->toISOString());
    }

    public function test_service_rechecks_persisted_session_version_before_a_write(): void
    {
        $user = User::factory()->create();
        User::whereKey($user->id)->increment('session_version');
        $this->expectException(AuthenticationException::class);
        app(AccountProfile::class)->update($user, ['name' => 'New Name', 'email' => 'new@example.test']);
    }
}
