<?php

namespace Tests\Feature;

use App\Mail\PasswordRecoveryMail;
use App\Models\OidcIdentity;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PasswordRecovery;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'Fictional original recovery password 123!';

    private const NEW = 'Fictional replacement recovery password 456!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
        config(['password_recovery.enabled' => true, 'password_recovery.url' => 'https://garden.example.test/', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'garden@example.test']);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['password' => self::OLD, ...$attributes]);
    }

    private function resetInput(User $user, string $token, array $extra = []): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => self::NEW, 'password_confirmation' => self::NEW, ...$extra];
    }

    private function requestLink(string $email, string $locale = 'en')
    {
        return $this->postJson('/api/v1/auth/forgot-password', ['email' => $email, 'locale' => $locale]);
    }

    private function work(): void
    {
        while ($job = Queue::connection('database')->pop('password-recovery')) {
            $job->fire();
        }
    }

    public function test_recovery_is_explicitly_disabled_and_does_not_enqueue(): void
    {
        config(['password_recovery.enabled' => false]);
        $this->getJson('/api/v1/auth/password-recovery')->assertExactJson(['data' => ['enabled' => false]]);
        $this->requestLink('fictional@example.test')->assertStatus(503)->assertJsonPath('error.code', 'passwordRecovery.unavailable');
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
    }

    public function test_only_trusted_root_urls_and_smtp_are_allowed(): void
    {
        foreach (['http://garden.example.test/', 'https://user:secret@garden.example.test/', 'https://garden.example.test/?evil=1', 'https://garden.example.test/#other', 'https://garden.example.test/path', 'javascript:alert(1)', "https://garden.example.test/\r\n"] as $url) {
            config(['password_recovery.url' => $url]);
            $this->assertFalse(app(PasswordRecovery::class)->ready());
        }
        foreach (['https://garden.example.test/', 'http://localhost:8080', 'http://127.0.0.1:18080/'] as $url) {
            config(['password_recovery.url' => $url]);
            $this->assertTrue(app(PasswordRecovery::class)->ready());
        }
        foreach (['log', 'array', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->assertFalse(app(PasswordRecovery::class)->ready());
        }
    }

    public function test_guest_status_is_private_no_store_and_has_no_configuration_values(): void
    {
        $response = $this->getJson('/api/v1/auth/password-recovery')->assertExactJson(['data' => ['enabled' => true]]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('example.test', $response->getContent());
    }

    public function test_unknown_inactive_and_active_addresses_have_identical_responses_and_encrypted_queue_jobs(): void
    {
        $active = $this->user();
        $inactive = $this->user(['is_active' => false]);
        foreach ([$active->email, $inactive->email, 'missing@example.test'] as $email) {
            $response = $this->requestLink($email)->assertNoContent();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $payload = DB::table('jobs')->latest('id')->value('payload');
            $this->assertStringNotContainsString($email, $payload);
            $this->assertStringNotContainsString(self::OLD, $payload);
        }
        $this->assertDatabaseCount('jobs', 3);
        Mail::assertNothingSent();
        $this->work();
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertSent(PasswordRecoveryMail::class, fn ($mail) => $mail->hasTo($active->email));
        Http::assertNothingSent();
    }

    public function test_link_contains_only_a_fragment_token_uses_configured_origin_and_is_hashed(): void
    {
        $user = $this->user();
        $this->withHeader('Host', 'attacker.example.test')->requestLink(strtoupper($user->email), 'fr')->assertNoContent();
        $this->work();
        Mail::assertSent(PasswordRecoveryMail::class, function ($mail) use ($user) {
            $this->assertSame('https://garden.example.test/', strtok($mail->resetUrl, '#'));
            $token = substr($mail->resetUrl, strpos($mail->resetUrl, '#reset-password=') + 16);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
            $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
            $this->assertNotSame($token, $stored);
            $this->assertTrue(Hash::check($token, $stored));
            $body = $mail->render();
            $this->assertStringContainsString('Ce lien à usage unique expire dans 60 minutes.', $body);
            $this->assertStringNotContainsString($user->email, $mail->resetUrl);
            $this->assertStringNotContainsString('attacker', $mail->resetUrl);

            return true;
        });
    }

    public function test_requests_are_silently_limited_per_address_and_new_link_invalidates_old_after_throttle(): void
    {
        $user = $this->user();
        $this->requestLink($user->email)->assertNoContent();
        $this->work();
        $oldHash = DB::table('password_reset_tokens')->value('token');
        $this->requestLink($user->email)->assertNoContent();
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->requestLink($user->email)->assertNoContent();
        $this->work();
        Mail::assertSentCount(2);
        $this->assertNotSame($oldHash, DB::table('password_reset_tokens')->value('token'));
    }

    public function test_request_ip_limit_is_independent_of_address_existence(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->requestLink('missing-'.$i.'@example.test')->assertNoContent();
        }
        $this->requestLink('other@example.test')->assertStatus(429);
    }

    public function test_stale_disabled_or_account_changed_jobs_do_not_send(): void
    {
        $user = $this->user();
        $this->requestLink($user->email)->assertNoContent();
        $user->increment('session_version');
        $this->work();
        $this->travel(61)->seconds();
        $this->requestLink($user->email)->assertNoContent();
        $this->travel(301)->seconds();
        $this->work();
        $this->requestLink($user->email)->assertNoContent();
        config(['password_recovery.enabled' => false]);
        $this->work();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_delivery_failure_is_generic_sanitized_and_rolls_back_token(): void
    {
        $user = $this->user();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('fictional-sensitive-smtp-content'));
        Log::shouldReceive('warning')->once()->with('account.password_recovery_delivery_failed');
        $this->requestLink($user->email)->assertNoContent();
        $this->work();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_invalid_expired_inactive_or_foreign_token_cannot_mutate_credentials(): void
    {
        $user = $this->user();
        $other = $this->user();
        $token = Password::broker()->createToken($user);
        foreach ([$this->resetInput($user, str_repeat('a', 64)), $this->resetInput($other, $token)] as $input) {
            $this->postJson('/api/v1/auth/reset-password', $input)->assertUnprocessable()->assertJsonPath('error.code', 'passwordRecovery.invalidLink');
        }
        $user->is_active = false;
        $user->save();
        $this->postJson('/api/v1/auth/reset-password', $this->resetInput($user, $token))->assertUnprocessable();
        $user->is_active = true;
        $user->save();
        $this->travel(61)->minutes();
        $this->postJson('/api/v1/auth/reset-password', $this->resetInput($user, $token))->assertUnprocessable();
        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
        $this->assertSame(0, $user->fresh()->session_version);
    }

    public function test_new_password_policy_and_token_shape_are_enforced_without_consuming_token(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);
        foreach (['short', str_repeat('x', 129), str_repeat(' ', 14)] as $password) {
            $this->postJson('/api/v1/auth/reset-password', $this->resetInput($user, $token, ['password' => $password, 'password_confirmation' => $password]))->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/v1/auth/reset-password', $this->resetInput($user, $token, ['password_confirmation' => 'Different password']))->assertUnprocessable();
        $this->postJson('/api/v1/auth/reset-password', $this->resetInput($user, 'malformed'))->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
    }

    public function test_reused_password_is_rejected_and_spaces_in_new_password_are_preserved(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);
        $this->postJson('/api/v1/auth/reset-password', $this->resetInput($user, $token, ['password' => self::OLD, 'password_confirmation' => self::OLD]))->assertUnprocessable()->assertJsonPath('error.code', 'passwordRecovery.passwordUnchanged');
        $password = '  A fictional spaced password  ';
        $this->postJson('/api/v1/auth/reset-password', $this->resetInput($user, $token, ['password' => $password, 'password_confirmation' => $password]))->assertNoContent();
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
    }

    public function test_reset_is_single_use_preserves_identity_and_privileges_and_removes_only_owned_security_records(): void
    {
        $user = $this->user(['remember_token' => 'fictional-remember']);
        $other = $this->user();
        OidcIdentity::create(['user_id' => $user->id, 'issuer' => 'https://identity.example.test/application/o/garden/', 'subject' => 'fictional-subject']);
        $identity = OidcIdentity::first()->getAttributes();
        $original = [...$user->getAttributes(), 'permission_role_ids' => $user->permission_role_ids];
        foreach ([$user, $other] as $owner) {
            DB::table('sessions')->insert(['id' => 'fictional-session-'.$owner->id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp]);
            $endpoint = 'https://fcm.googleapis.com/fcm/send/fictional-recovery-'.$owner->id;
            PushSubscription::create(['user_id' => $owner->id, 'endpoint_hash' => hash('sha256', $endpoint), 'session_hash' => hash('sha256', 'fictional-session'), 'endpoint' => $endpoint, 'keys' => ['p256dh' => 'fictional-public', 'auth' => 'fictional-auth'], 'vapid_key_hash' => hash('sha256', 'fictional-vapid'), 'user_version' => 0]);
            Password::broker()->createToken($owner);
        }
        $token = Password::broker()->createToken($user);
        config(['session.driver' => 'database']);
        $this->postJson('/api/v1/auth/reset-password', $this->resetInput($user, $token, ['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id], 'user_id' => $other->id, 'is_active' => false]))->assertNoContent();
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::NEW, $fresh->password));
        $this->assertSame(1, $fresh->session_version);
        $this->assertNull($fresh->remember_token);
        foreach (['name', 'email', 'permission_role_ids', 'is_active', 'email_verified_at'] as $field) {
            $this->assertEquals($original[$field], ($field === 'permission_role_ids' ? $fresh->permission_role_ids : $fresh->getAttributes()[$field]));
        }
        $this->assertSame($identity, OidcIdentity::first()->getAttributes());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
        $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $other->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);
        $this->postJson('/api/v1/auth/reset-password', $this->resetInput($user, $token))->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::OLD])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::NEW])->assertOk();
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_preexisting_session_is_rejected_by_account_version_after_reset(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);
        app(PasswordRecovery::class)->reset($user->email, $token, self::NEW);
        $this->actingAs($user->fresh())->withSession(['auth_version' => 0])->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_request_validation_rejects_invalid_email_locale_and_oversized_values(): void
    {
        foreach ([['email' => 'bad', 'locale' => 'en'], ['email' => str_repeat('x', 255).'@example.test', 'locale' => 'en'], ['email' => 'fictional@example.test', 'locale' => 'de']] as $input) {
            $this->postJson('/api/v1/auth/forgot-password', $input)->assertUnprocessable();
        }
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_administrator_sensitive_changes_invalidate_reset_links_including_previous_email(): void
    {
        $admin = $this->user(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $user = $this->user();
        foreach (['password', 'email', 'permission_role_ids', 'is_active'] as $field) {
            $user->refresh();
            $previousEmail = $user->email;
            $token = Password::broker()->createToken($user);
            $data = ['name' => $user->name, 'email' => $user->email, 'permission_role_ids' => $user->permission_role_ids, 'is_active' => $user->is_active];
            $data[$field] = match ($field) {
                'password' => 'A fictional administrator replacement 789!',
                'email' => 'changed-recovery@example.test',
                'permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id],
                'is_active' => false,
            };
            if ($field === 'password') {
                $data['password_confirmation'] = $data['password'];
            }
            $this->actingAs($admin)->patchJson('/api/v1/users/'.$user->uuid, $data)->assertOk();
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $previousEmail]);
            $this->assertFalse(Password::broker()->tokenExists($user->fresh(), $token));
        }
    }

    public function test_recovery_audit_contains_only_the_account_uuid(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);
        Log::spy();
        app(PasswordRecovery::class)->reset($user->email, $token, self::NEW);
        Log::shouldHaveReceived('notice')->once()->with('account.password_recovered', ['actor_uuid' => $user->uuid]);
    }
}
