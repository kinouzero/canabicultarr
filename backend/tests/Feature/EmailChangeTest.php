<?php

namespace Tests\Feature;

use App\Mail\EmailChangeMail;
use App\Models\OidcIdentity;
use App\Models\PendingEmailChange;
use App\Models\User;
use App\Services\AccountSecurity;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        config(['queue.default' => 'database', 'app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test', 'auth.local_enabled' => true]);
    }

    private function requestChange(User $user, string $email = 'replacement@example.test'): void
    {
        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => $user->name, 'email' => $email, 'locale' => 'fr'])
            ->assertOk()->assertJsonPath('data.email', $user->email)->assertJsonPath('data.pendingEmail', $email);
    }

    private function deliver(): string
    {
        Mail::fake();
        $payload = DB::table('jobs')->value('payload');
        $job = Queue::connection('database')->pop('account-activation');
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();
        $token = '';
        Mail::assertSent(EmailChangeMail::class, function ($mail) use (&$token) {
            $token = explode('#confirm-email=', $mail->confirmationUrl)[1];

            return strlen($token) === 64 && $mail->hasTo('replacement@example.test') && $mail->locale === 'fr';
        });
        $this->assertStringNotContainsString($token, $payload);
        $this->assertStringNotContainsString('replacement@example.test', $payload);

        return $token;
    }

    public function test_confirmation_is_single_use_and_preserves_oidc_access_and_last_administrator(): void
    {
        $user = User::factory()->create(['email' => 'current@example.test', 'permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $identity = OidcIdentity::create(['user_id' => $user->id, 'issuer' => 'https://identity.example.test', 'subject' => 'same-subject']);
        $password = $user->password;
        $verified = $user->email_verified_at->toISOString();
        $this->requestChange($user);
        Mail::assertNothingSent();
        $this->assertSame($verified, $user->fresh()->email_verified_at->toISOString());
        $this->assertSame(0, $user->fresh()->session_version);
        $token = $this->deliver();
        $this->assertSame(hash('sha256', $token), PendingEmailChange::first()->token_hash);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'unused-reset', 'created_at' => now()]);
        $body = ['email' => 'replacement@example.test', 'token' => $token];
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/email-change/confirm', $body)->assertNoContent();
        $fresh = $user->fresh();
        $this->assertSame($body['email'], $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertSame($password, $fresh->password);
        $this->assertSame(1, $fresh->session_version);
        $this->assertTrue($fresh->isAdministrator());
        $this->assertSame('same-subject', $identity->fresh()->subject);
        $this->assertDatabaseCount('pending_email_changes', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->postJson('/api/v1/auth/email-change/confirm', $body)->assertUnprocessable();
        $this->actingAs($fresh)->withSession(['auth_version' => 0])->getJson('/api/v1/auth/user')->assertUnauthorized();
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'current@example.test', 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $body['email'], 'password' => 'password'])->assertOk();
    }

    public function test_wrong_address_token_and_expiry_never_replace_the_current_email(): void
    {
        $user = User::factory()->create();
        $this->requestChange($user);
        $token = $this->deliver();
        foreach ([['email' => $user->email, 'token' => $token], ['email' => 'replacement@example.test', 'token' => str_repeat('x', 64)]] as $body) {
            $this->postJson('/api/v1/auth/email-change/confirm', $body)->assertUnprocessable();
        }
        $this->travel(61)->minutes();
        $this->postJson('/api/v1/auth/email-change/confirm', ['email' => 'replacement@example.test', 'token' => $token])->assertUnprocessable();
        $this->assertSame($user->email, $user->fresh()->email);
        $this->getJson('/api/v1/auth/user')->assertOk()->assertJsonPath('data.pendingEmail', null);
    }

    public function test_resend_rotates_tokens_cancel_revokes_them_and_name_edits_do_not_resend(): void
    {
        $user = User::factory()->create();
        $this->requestChange($user);
        $first = $this->deliver();
        $this->patchJson('/api/v1/account/profile', ['name' => 'New display name', 'email' => $user->email])->assertOk()->assertJsonPath('data.pendingEmail', 'replacement@example.test');
        $this->requestChange($user);
        $this->assertDatabaseCount('jobs', 0);
        $this->postJson('/api/v1/account/email-change/resend')->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/account/email-change/resend', ['locale' => 'fr'])->assertOk();
        $second = $this->deliver();
        $this->assertNotSame($first, $second);
        $this->postJson('/api/v1/auth/email-change/confirm', ['email' => 'replacement@example.test', 'token' => $first])->assertUnprocessable();
        $this->deleteJson('/api/v1/account/email-change')->assertOk()->assertJsonPath('data.pendingEmail', null);
        $this->postJson('/api/v1/auth/email-change/confirm', ['email' => 'replacement@example.test', 'token' => $second])->assertUnprocessable();
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_existing_and_reserved_addresses_cannot_be_claimed_and_confirmation_handles_a_late_collision(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => 'Rejected edit', 'email' => $other->email])->assertUnprocessable();
        $this->requestChange($user);
        $token = $this->deliver();
        $this->actingAs($other)->patchJson('/api/v1/account/profile', ['name' => $other->name, 'email' => 'replacement@example.test'])->assertUnprocessable();
        $claimant = User::factory()->create(['email' => 'replacement@example.test']);
        $this->postJson('/api/v1/auth/email-change/confirm', ['email' => 'replacement@example.test', 'token' => $token])->assertUnprocessable()->assertJsonPath('error.code', 'emailChange.emailTaken');
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame('replacement@example.test', $claimant->fresh()->email);
    }

    public function test_admin_requests_require_current_permissions_and_inactive_targets_stay_inactive(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$user->uuid, ['name' => $user->name, 'email' => 'replacement@example.test', 'is_active' => false, 'permission_role_ids' => $user->permission_role_ids, 'locale' => 'fr'])->assertOk();
        $token = $this->deliver();
        $admin->permissionRoles()->sync([AccessProfileSeeder::defaultProfile()->id]);
        $this->postJson('/api/v1/auth/email-change/confirm', ['email' => 'replacement@example.test', 'token' => $token])->assertForbidden();
        $this->assertSame($user->email, $user->fresh()->email);
        $admin->permissionRoles()->sync([AccessProfileSeeder::defaultProfile('administrator')->id]);
        $this->postJson('/api/v1/auth/email-change/confirm', ['email' => 'replacement@example.test', 'token' => $token])->assertNoContent();
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_account_security_changes_revoke_pending_capabilities(): void
    {
        $user = User::factory()->create();
        $this->requestChange($user);
        $token = $this->deliver();
        DB::transaction(fn () => app(AccountSecurity::class)->invalidate($user));
        $this->assertDatabaseCount('pending_email_changes', 0);
        $this->postJson('/api/v1/auth/email-change/confirm', ['email' => 'replacement@example.test', 'token' => $token])->assertUnprocessable();
    }

    public function test_guest_and_non_administrator_cannot_manage_someone_elses_request_or_admin_routes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->deleteJson('/api/v1/account/email-change')->assertUnauthorized();
        $this->actingAs($other)->deleteJson('/api/v1/users/'.$user->uuid.'/email-change')->assertForbidden();
        $this->deleteJson('/api/v1/users/'.$other->uuid.'/email-change')->assertForbidden();
        $this->postJson('/api/v1/users/'.$other->uuid.'/email-change/resend')->assertForbidden();
    }

    public function test_unavailable_mail_and_queue_failure_leave_the_old_account_usable(): void
    {
        $user = User::factory()->create();
        config(['mail.default' => 'log']);
        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => 'Rejected edit', 'email' => 'replacement@example.test'])->assertStatus(503);
        config(['mail.default' => 'smtp']);
        Queue::shouldReceive('connection')->withNoArgs()->andThrow(new \RuntimeException('private queue credentials'));
        $this->patchJson('/api/v1/account/profile', ['name' => 'Rejected edit', 'email' => 'replacement@example.test'])->assertStatus(503)->assertExactJson(['error' => ['code' => 'emailChange.unavailable']]);
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame(0, $user->fresh()->session_version);
        $this->assertDatabaseCount('pending_email_changes', 0);
    }

    public function test_smtp_failure_does_not_log_secrets_or_replace_the_current_address(): void
    {
        $user = User::factory()->create();
        $this->requestChange($user);
        Log::spy();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('private smtp credentials'));
        $job = Queue::connection('database')->pop('account-activation');
        $job->fire();
        $job->delete();
        Log::shouldHaveReceived('warning')->with('account.email_change_delivery_failed', ['user_uuid' => $user->uuid])->once();
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertNotNull(PendingEmailChange::first());
    }

    public function test_transient_schema_rollback_preserves_existing_account_identity(): void
    {
        $user = User::factory()->create();
        $attributes = $user->fresh()->getAttributes();
        $identity = OidcIdentity::create(['user_id' => $user->id, 'issuer' => 'https://identity.example.test', 'subject' => 'preserved-subject']);
        $this->requestChange($user);
        $migration = require database_path('migrations/2026_11_15_090000_create_pending_email_changes.php');
        $migration->down();
        $this->assertSame($attributes, $user->fresh()->getAttributes());
        $this->assertSame('preserved-subject', $identity->fresh()->subject);
        $migration->up();
        $this->assertDatabaseCount('pending_email_changes', 0);
    }
}
