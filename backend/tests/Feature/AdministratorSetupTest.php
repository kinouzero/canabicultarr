<?php

namespace Tests\Feature;

use App\Models\ApplicationSetup;
use App\Models\User;
use App\Services\AdministratorSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdministratorSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Cache::flush();
        Mail::fake();
        config(['app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test']);
    }

    private function payload(string $token): array
    {
        return [
            'token' => $token,
            'name' => 'Test Administrator',
            'email' => 'ADMIN@example.test',
            'password' => 'A long test password 123!',
            'password_confirmation' => 'A long test password 123!',
        ];
    }

    public function test_token_is_hashed_and_expires_in_one_hour(): void
    {
        $this->freezeTime();
        $token = app(AdministratorSetup::class)->issueToken();
        $setup = ApplicationSetup::findOrFail(1);

        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $setup->token_hash);
        $this->assertSame(now()->addHour()->timestamp, $setup->token_expires_at->timestamp);
        $this->assertArrayNotHasKey('token_hash', $setup->toArray());
    }

    public function test_first_administrator_is_created_once_and_requires_activation(): void
    {
        Log::spy();
        $token = app(AdministratorSetup::class)->issueToken();

        $this->postJson('/api/v1/setup', $this->payload($token))
            ->assertCreated()->assertExactJson(['data' => ['activationRequired' => true]]);

        $user = User::sole();
        $this->assertTrue(Str::isUuid($user->uuid));
        $this->assertTrue($user->email_activation_required);
        $this->assertTrue($user->isAdministrator());
        $this->assertTrue(Hash::check($this->payload($token)['password'], $user->password));
        $this->assertSame('argon2id', password_get_info($user->password)['algoName']);
        $this->assertNull($user->email_verified_at);
        $this->assertGuest();
        $this->assertNull(ApplicationSetup::findOrFail(1)->token_hash);
        $this->getJson('/api/v1/setup')->assertJsonPath('data.requiresSetup', false);
        $this->postJson('/api/v1/setup', $this->payload($token))->assertStatus(409);
        $this->assertDatabaseCount('users', 1);

        Log::shouldHaveReceived('notice')->with('administrator.setup_token.issued')->once();
        Log::shouldHaveReceived('notice')->with('administrator.setup.completed', ['user_uuid' => $user->uuid])->once();
    }

    public function test_wrong_and_expired_tokens_cannot_create_a_user(): void
    {
        $token = app(AdministratorSetup::class)->issueToken();
        $this->postJson('/api/v1/setup', $this->payload(str_repeat('x', 64)))
            ->assertForbidden()->assertJsonPath('error.code', 'auth.errors.invalidSetupToken');

        $this->travelTo(ApplicationSetup::findOrFail(1)->token_expires_at);
        $this->postJson('/api/v1/setup', $this->payload($token))
            ->assertForbidden()->assertJsonPath('error.code', 'auth.errors.invalidSetupToken');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_no_token_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/setup', $this->payload(''))
            ->assertUnprocessable()->assertJsonPath('errors.token.0', 'auth.validation.tokenRequired');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_rotating_the_token_invalidates_the_previous_one(): void
    {
        $old = app(AdministratorSetup::class)->issueToken();
        $current = app(AdministratorSetup::class)->issueToken();
        $this->postJson('/api/v1/setup', $this->payload($old))->assertForbidden();
        $this->postJson('/api/v1/setup', $this->payload($current))->assertCreated();
    }

    public function test_existing_users_cannot_be_claimed_by_setup(): void
    {
        $token = app(AdministratorSetup::class)->issueToken();
        User::factory()->create();
        $this->getJson('/api/v1/setup')->assertJsonPath('data.requiresSetup', false);
        $this->postJson('/api/v1/setup', $this->payload($token))->assertStatus(409);
        $this->artisan('app:setup-token', ['--raw' => true])->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_setup_remains_closed_if_the_administrator_is_deleted(): void
    {
        $token = app(AdministratorSetup::class)->issueToken();
        $this->postJson('/api/v1/setup', $this->payload($token))->assertCreated();
        User::query()->delete();
        $this->getJson('/api/v1/setup')->assertJsonPath('data.requiresSetup', false);
        $this->artisan('app:setup-token')->assertExitCode(1);
    }

    public function test_password_length_and_confirmation_are_enforced(): void
    {
        $token = app(AdministratorSetup::class)->issueToken();
        $data = $this->payload($token);
        $data['password'] = $data['password_confirmation'] = 'short';
        $this->postJson('/api/v1/setup', $data)->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'auth.validation.passwordLength');
        $data = $this->payload($token);
        $data['password_confirmation'] = 'A different test password';
        $this->postJson('/api/v1/setup', $data)->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'auth.validation.passwordConfirmation');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_passwords_that_look_like_hashes_are_still_treated_as_plaintext(): void
    {
        $token = app(AdministratorSetup::class)->issueToken();
        $data = $this->payload($token);
        $hashShapedPassword = Hash::make('short');
        $data['password'] = $data['password_confirmation'] = $hashShapedPassword;
        $this->postJson('/api/v1/setup', $data)->assertCreated();

        $this->assertTrue(Hash::check($hashShapedPassword, User::sole()->password));
        $this->assertFalse(Hash::check('short', User::sole()->password));
    }

    public function test_setup_attempts_are_rate_limited(): void
    {
        $token = app(AdministratorSetup::class)->issueToken();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/setup', $this->payload(str_repeat('x', 64)))->assertForbidden();
        }
        $this->postJson('/api/v1/setup', $this->payload($token))->assertStatus(429);
        $this->assertDatabaseCount('users', 0);
    }
}
