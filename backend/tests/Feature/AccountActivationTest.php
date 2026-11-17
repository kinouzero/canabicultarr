<?php

namespace Tests\Feature;

use App\Mail\AccountActivationMail;
use App\Models\User;
use App\Services\AccountActivation;
use App\Services\AdministratorSetup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Cache::flush();
        Mail::fake();
        config(['app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test', 'auth.registration_enabled' => true]);
    }

    private function register(): User
    {
        User::factory()->create();
        $this->postJson('/api/v1/auth/register', ['name' => 'New grower', 'email' => 'grower@example.test', 'password' => 'A long test password 123!', 'password_confirmation' => 'A long test password 123!', 'locale' => 'fr'])
            ->assertCreated()->assertExactJson(['data' => ['activationRequired' => true]]);

        return User::where('email', 'grower@example.test')->firstOrFail();
    }

    private function deliver(): string
    {
        $job = Queue::connection('database')->pop('account-activation');
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();
        $token = '';
        Mail::assertSent(AccountActivationMail::class, function ($mail) use (&$token) {
            $token = substr($mail->activationUrl, strpos($mail->activationUrl, '#activate-account=') + strlen('#activate-account='));

            return strlen($token) === 64 && str_starts_with($mail->activationUrl, 'https://culture.example.test/');
        });

        return $token;
    }

    public function test_new_accounts_need_single_use_email_confirmation_before_login(): void
    {
        $user = $this->register();
        $this->assertGuest();
        $this->assertTrue($user->email_activation_required);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'A long test password 123!'])->assertForbidden()->assertJsonPath('error.code', 'accountActivation.required');
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
        $payload = DB::table('jobs')->value('payload');
        $token = $this->deliver();
        $this->assertSame(hash('sha256', $token), DB::table('account_activation_tokens')->value('token_hash'));
        $this->assertStringNotContainsString($token, $payload);
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => ' GROWER@example.test ', 'token' => $token])->assertNoContent();
        $this->assertGuest();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertFalse($user->fresh()->email_activation_required);
        $this->assertDatabaseCount('account_activation_tokens', 0);
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => $user->email, 'token' => $token])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'A long test password 123!'])->assertOk();
        $this->getJson('/api/v1/auth/user')->assertOk();
    }

    public function test_expired_wrong_and_changed_email_links_cannot_activate(): void
    {
        $user = $this->register();
        $token = $this->deliver();
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => $user->email, 'token' => str_repeat('x', 64)])->assertUnprocessable();
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => 'unknown@example.test', 'token' => $token])->assertUnprocessable();
        $user->update(['email' => 'changed@example.test']);
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => $user->email, 'token' => $token])->assertUnprocessable();
        $user->update(['email' => 'grower@example.test']);
        $this->travelTo(CarbonImmutable::parse(DB::table('account_activation_tokens')->value('expires_at')));
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => $user->email, 'token' => $token])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_is_generic_throttled_and_rotates_the_link(): void
    {
        $user = $this->register();
        $old = $this->deliver();
        foreach ([$user->email, $user->email, 'absent@example.test'] as $email) {
            $this->postJson('/api/v1/auth/activation/resend', ['email' => $email])->assertNoContent();
        }
        $this->assertDatabaseCount('jobs', 1);
        $this->assertNotSame(hash('sha256', $old), DB::table('account_activation_tokens')->value('token_hash'));
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => $user->email, 'token' => $old])->assertUnprocessable();
        $user->is_active = false;
        $user->save();
        $job = Queue::connection('database')->pop('account-activation');
        Mail::fake();
        $job->fire();
        $job->delete();
        Mail::assertNothingSent();
    }

    public function test_unavailable_mail_rolls_back_registration_and_setup_without_consuming_setup_token(): void
    {
        config(['mail.default' => 'log']);
        $token = app(AdministratorSetup::class)->issueToken();
        $input = ['name' => 'New administrator', 'email' => 'admin@example.test', 'password' => 'A long test password 123!', 'password_confirmation' => 'A long test password 123!', 'token' => $token];
        $this->postJson('/api/v1/setup', $input)->assertStatus(503)->assertJsonPath('error.code', 'accountActivation.unavailable');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertTrue(app(AdministratorSetup::class)->isRequired());
        $this->assertSame(hash('sha256', $token), DB::table('application_setup')->value('token_hash'));
        User::factory()->create();
        $this->postJson('/api/v1/auth/register', $input)->assertStatus(503);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_activation_tokens', 0);
    }

    public function test_migration_preserves_existing_accounts_without_claiming_their_email_is_verified(): void
    {
        $user = User::factory()->create(['email_verified_at' => null, 'password' => 'A long test password 123!']);
        $this->reapplySchemaFrom('2026_11_13_180000_add_account_activation.php');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertFalse($user->fresh()->email_activation_required);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'A long test password 123!'])->assertOk();
    }

    public function test_queue_failure_does_not_reserve_an_address_or_expose_transport_details(): void
    {
        User::factory()->create();
        Queue::shouldReceive('connection')->withNoArgs()->andThrow(new \RuntimeException('Private queue connection details'));
        $response = $this->postJson('/api/v1/auth/register', ['name' => 'New grower', 'email' => 'grower@example.test', 'password' => 'A long test password 123!', 'password_confirmation' => 'A long test password 123!']);
        $response->assertStatus(503)->assertExactJson(['error' => ['code' => 'accountActivation.unavailable']]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_activation_tokens', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_pending_accounts_cannot_use_existing_sessions_and_unsafe_origins_are_rejected(): void
    {
        $user = $this->register();
        $this->actingAs($user)->getJson('/api/v1/auth/user')->assertUnauthorized();
        foreach (['http://remote.example.test', 'https://culture.example.test/?redirect=evil', 'https://user:secret@culture.example.test'] as $url) {
            config(['app.url' => $url]);
            $this->assertFalse(app(AccountActivation::class)->ready());
        }
    }
}
