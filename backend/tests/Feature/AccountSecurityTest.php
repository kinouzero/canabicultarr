<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\OidcIdentity;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AccountSecurity;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'A fictional current password 123!';

    private const NEW_PASSWORD = 'A fictional replacement password 456!';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo(now()->startOfSecond());
    }

    private function user(array $extra = []): User
    {
        return User::factory()->create(['password' => self::OLD_PASSWORD, ...$extra]);
    }

    private function input(array $extra = []): array
    {
        return ['current_password' => self::OLD_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD, ...$extra];
    }

    private function recordedSession(User $owner, string $id, ?int $time = null): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'ip_address' => '192.0.2.10', 'user_agent' => 'Fictional Private Browser', 'payload' => 'fictional-private-payload', 'last_activity' => $time ?? now()->timestamp]);
    }

    private function subscription(User $owner, string $suffix): PushSubscription
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/fictional-'.$suffix;

        return PushSubscription::create(['user_id' => $owner->id, 'endpoint_hash' => hash('sha256', $endpoint), 'session_hash' => hash('sha256', 'fictional-session'), 'endpoint' => $endpoint, 'keys' => ['p256dh' => 'fictional-public-key', 'auth' => 'fictional-auth-key'], 'vapid_key_hash' => hash('sha256', 'fictional-vapid-key'), 'user_version' => $owner->session_version]);
    }

    public function test_guests_cannot_read_or_change_account_security(): void
    {
        $this->getJson('/api/v1/account/security')->assertUnauthorized();
        $this->postJson('/api/v1/account/password', $this->input())->assertUnauthorized();
        $this->postJson('/api/v1/account/sessions/revoke', ['current_password' => self::OLD_PASSWORD])->assertUnauthorized();
    }

    public function test_members_and_admins_read_only_private_capabilities(): void
    {
        foreach (['observer', 'administrator'] as $profile) {
            $user = $this->user(['permission_role_ids' => [AccessProfileSeeder::defaultProfile($profile)->id]]);
            $response = $this->actingAs($user)->getJson('/api/v1/account/security')->assertOk()->assertExactJson(['data' => ['localPasswordAvailable' => true, 'databaseSessions' => false, 'otherSessions' => null]]);
            foreach ([$user->email, $user->uuid, $user->password, self::OLD_PASSWORD] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_inactive_and_stale_sessions_are_denied(): void
    {
        $inactive = $this->user(['is_active' => false]);
        $this->assertFalse(Gate::forUser($inactive)->allows('account.security'));
        $this->actingAs($inactive)->getJson('/api/v1/account/security')->assertUnauthorized();
        $revoked = $this->user(['session_version' => 1]);
        $this->actingAs($revoked)->withSession(['auth_version' => 0])->postJson('/api/v1/account/password', $this->input())->assertUnauthorized();
    }

    public function test_wrong_current_password_preserves_credentials_and_sessions(): void
    {
        $user = $this->user(['remember_token' => 'fictional-remember-token']);
        $hash = $user->password;
        $this->recordedSession($user, 'preserved-session');
        $this->actingAs($user)->postJson('/api/v1/account/password', $this->input(['current_password' => 'Wrong current password']))->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'accountSecurity.errors.currentPasswordInvalid');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(0, $user->fresh()->session_version);
        $this->assertDatabaseHas('sessions', ['id' => 'preserved-session']);
        $this->assertSame('fictional-remember-token', $user->fresh()->remember_token);
    }

    public function test_password_policy_and_confirmation_are_enforced_before_writes(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $hash = $user->password;
        foreach (['short', str_repeat('x', 129), str_repeat(' ', 15)] as $value) {
            $this->postJson('/api/v1/account/password', $this->input(['password' => $value, 'password_confirmation' => $value]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/account/password', $this->input(['password_confirmation' => 'Different confirmed password']))->assertUnprocessable()->assertJsonPath('errors.password.0', 'auth.validation.passwordConfirmation');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(0, $user->fresh()->session_version);
    }

    public function test_reusing_the_current_password_is_rejected(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/api/v1/account/password', $this->input(['password' => self::OLD_PASSWORD, 'password_confirmation' => self::OLD_PASSWORD]))->assertUnprocessable()->assertJsonPath('errors.password.0', 'accountSecurity.errors.passwordUnchanged');
        $this->assertSame(0, $user->fresh()->session_version);
    }

    public function test_change_only_updates_own_credentials_ignores_target_or_privilege_fields_and_signs_out(): void
    {
        $user = $this->user(['remember_token' => 'fictional-remember-token']);
        $other = $this->user();
        $original = $user->fresh()->getAttributes();
        $otherHash = $other->password;
        $response = $this->actingAs($user)->postJson('/api/v1/account/password', $this->input(['id' => $other->uuid, 'user_id' => $other->id, 'permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id], 'email' => 'changed@example.test', 'is_active' => false]))->assertNoContent();
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $fresh->password));
        $this->assertSame(1, $fresh->session_version);
        $this->assertNull($fresh->remember_token);
        foreach (['name', 'email', 'role', 'is_active', 'email_verified_at', 'uuid'] as $field) {
            $this->assertSame($original[$field] ?? null, $fresh->getRawOriginal($field));
        }
        $this->assertSame($otherHash, $other->fresh()->password);
        $this->assertSame(0, $other->fresh()->session_version);
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_all_owned_database_sessions_are_deleted_and_foreign_sessions_are_preserved(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->user();
        $other = $this->user();
        $this->recordedSession($user, 'own-first');
        $this->recordedSession($user, 'own-expired', now()->subDays(2)->timestamp);
        $this->recordedSession($other, 'other-user');
        app(AccountSecurity::class)->apply($user, self::OLD_PASSWORD);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => 'other-user']);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
        $this->assertSame(1, $user->fresh()->session_version);
    }

    public function test_owned_push_and_reset_tokens_are_removed_without_disabling_email_preferences(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->subscription($user, 'owner');
        $otherSubscription = $this->subscription($other, 'other');
        NotificationPreference::create(['user_id' => $user->id, 'email_enabled' => true, 'push_enabled' => true, 'locale' => 'fr', 'version' => 2]);
        foreach ([$user, $other] as $owner) {
            DB::table('password_reset_tokens')->insert(['email' => $owner->email, 'token' => 'fictional-reset-token', 'created_at' => now()]);
        }
        app(AccountSecurity::class)->apply($user, self::OLD_PASSWORD, self::NEW_PASSWORD);
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertNotNull($otherSubscription->fresh());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);
        $this->assertTrue(NotificationPreference::find($user->id)->email_enabled);
        $this->assertSame(2, NotificationPreference::find($user->id)->version);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_application_oidc_identity_is_preserved_without_provider_calls(): void
    {
        $user = $this->user();
        $identity = OidcIdentity::create(['user_id' => $user->id, 'issuer' => 'https://identity.example.test/application/o/app/', 'subject' => 'fictional-subject']);
        app(AccountSecurity::class)->apply($user, self::OLD_PASSWORD, self::NEW_PASSWORD);
        $this->assertNotNull($identity->fresh());
        $this->assertSame($user->id, $identity->fresh()->user_id);
        Http::assertNothingSent();
    }

    public function test_revoke_endpoint_keeps_password_and_invalidates_current_login(): void
    {
        $user = $this->user(['remember_token' => 'fictional-remember-token']);
        $hash = $user->password;
        $this->actingAs($user)->postJson('/api/v1/account/sessions/revoke', ['current_password' => self::OLD_PASSWORD])->assertNoContent();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertNull($user->fresh()->remember_token);
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_non_database_session_backends_are_revoked_by_account_version(): void
    {
        config(['session.driver' => 'cookie']);
        $user = $this->user();
        $this->recordedSession($user, 'untracked-row');
        $this->assertSame(['localPasswordAvailable' => true, 'databaseSessions' => false, 'otherSessions' => null], app(AccountSecurity::class)->status($user, 'current'));
        app(AccountSecurity::class)->apply($user, self::OLD_PASSWORD);
        $this->assertDatabaseHas('sessions', ['id' => 'untracked-row']);
        $this->actingAs($user->fresh())->getJson('/api/v1/account/security')->assertUnauthorized();
    }

    public function test_session_count_excludes_current_foreign_and_expired_rows_without_exposing_metadata(): void
    {
        config(['session.driver' => 'database', 'session.lifetime' => 120]);
        $user = $this->user();
        $other = $this->user();
        $this->recordedSession($user, 'current');
        $this->recordedSession($user, 'recent');
        $this->recordedSession($user, 'boundary', now()->timestamp - 7200);
        $this->recordedSession($user, 'expired', now()->timestamp - 7201);
        $this->recordedSession($other, 'foreign');
        $status = app(AccountSecurity::class)->status($user, 'current');
        $this->assertSame(['localPasswordAvailable' => true, 'databaseSessions' => true, 'otherSessions' => 2], $status);
        foreach (['192.0.2.10', 'Fictional Private Browser', 'fictional-private-payload', 'current'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($status));
        }
    }

    public function test_stale_actor_is_rejected_inside_the_transaction(): void
    {
        $user = $this->user();
        User::whereKey($user->id)->update(['session_version' => 1]);
        $this->expectException(AuthenticationException::class);
        app(AccountSecurity::class)->apply($user, self::OLD_PASSWORD, self::NEW_PASSWORD);
    }

    public function test_leading_and_trailing_password_spaces_are_preserved(): void
    {
        $old = '  A fictional current password 123!  ';
        $new = '  A fictional new password 456!  ';
        $user = $this->user(['password' => $old]);
        $this->actingAs($user)->postJson('/api/v1/account/password', $this->input(['current_password' => $old, 'password' => $new, 'password_confirmation' => $new]))->assertNoContent();
        $this->assertTrue(Hash::check($new, $user->fresh()->password));
        $this->assertFalse(Hash::check(trim($new), $user->fresh()->password));
    }

    public function test_missing_local_password_disables_the_confirmation_flow(): void
    {
        $user = $this->user();
        DB::table('users')->where('id', $user->id)->update(['password' => '']);
        $user->refresh();
        $this->actingAs($user)->getJson('/api/v1/account/security')->assertJsonPath('data.localPasswordAvailable', false);
        $this->postJson('/api/v1/account/sessions/revoke', ['current_password' => self::OLD_PASSWORD])->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'accountSecurity.errors.localPasswordUnavailable');
        $this->assertSame(0, $user->fresh()->session_version);
    }

    public function test_sensitive_routes_share_one_account_rate_limit(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/account/password', $this->input(['current_password' => 'Wrong password']))->assertUnprocessable();
        }
        $this->postJson('/api/v1/account/sessions/revoke', ['current_password' => self::OLD_PASSWORD])->assertTooManyRequests();
        $this->assertSame(0, $user->fresh()->session_version);
    }

    public function test_audit_records_only_the_actor_and_operation(): void
    {
        $user = $this->user();
        Log::spy();
        app(AccountSecurity::class)->apply($user, self::OLD_PASSWORD, self::NEW_PASSWORD);
        Log::shouldHaveReceived('notice')->once()->with('account.password_changed', ['actor_uuid' => $user->uuid]);
    }

    public function test_last_active_administrator_can_change_password_without_changing_role(): void
    {
        $admin = $this->user(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($admin)->postJson('/api/v1/account/password', $this->input())->assertNoContent();
        $this->assertTrue($admin->fresh()->isAdministrator());
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame(1, User::withPermission('administration.view')->where('is_active', true)->count());
    }

    public function test_oversized_current_password_is_rejected_without_credential_changes(): void
    {
        $user = $this->user();
        $hash = $user->password;
        $this->actingAs($user)->postJson('/api/v1/account/sessions/revoke', ['current_password' => str_repeat('x', 129)])->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'accountSecurity.errors.currentPasswordRequired');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(0, $user->fresh()->session_version);
    }
}
