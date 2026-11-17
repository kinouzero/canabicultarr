<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LocalAuthenticationTest extends TestCase
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

    public function test_disabled_password_login_is_enforced_by_the_server(): void
    {
        $user = User::factory()->create(['password' => 'A long test password 123!']);
        config(['auth.local_enabled' => false, 'auth.registration_enabled' => true]);
        $this->getJson('/api/v1/setup')->assertOk()->assertJsonPath('data.localEnabled', false)->assertJsonPath('data.registrationEnabled', false);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'A long test password 123!'])->assertForbidden()->assertJsonPath('error.code', 'auth.errors.localDisabled');
        $this->getJson('/api/v1/auth/password-recovery')->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email, 'locale' => 'en'])->assertStatus(503);
        $this->assertGuest();
    }

    public function test_registration_requires_both_registration_and_local_login_to_be_enabled(): void
    {
        User::factory()->create();
        $data = ['name' => 'New member', 'email' => 'new@example.test', 'password' => 'A long test password 123!', 'password_confirmation' => 'A long test password 123!'];
        foreach ([[true, false], [false, true]] as [$local, $registration]) {
            config(['auth.local_enabled' => $local, 'auth.registration_enabled' => $registration]);
            $this->postJson('/api/v1/auth/register', $data)->assertForbidden()->assertJsonPath('error.code', 'auth.errors.registrationDisabled');
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_registration_creates_a_pending_observer_and_ignores_privilege_inputs(): void
    {
        User::factory()->create();
        config(['auth.local_enabled' => true, 'auth.registration_enabled' => true]);
        $this->postJson('/api/v1/auth/register', ['name' => 'New member', 'email' => ' NEW@example.test ', 'password' => 'A long test password 123!', 'password_confirmation' => 'A long test password 123!', 'permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id], 'permission_grants' => ['administration.view']])
            ->assertCreated()->assertExactJson(['data' => ['activationRequired' => true]]);
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('A long test password 123!', $user->password));
        $this->assertFalse($user->isAdministrator());
        $this->assertEmpty($user->permission_grants);
        $this->assertTrue($user->email_activation_required);
        $this->assertSame('Observer', $user->permissionRoles->first()->name);
        $this->assertGuest();
    }

    public function test_registration_validates_confirmation_and_normalized_duplicate_email_and_cannot_replace_setup(): void
    {
        config(['auth.local_enabled' => true, 'auth.registration_enabled' => true]);
        $data = ['name' => 'New member', 'email' => 'new@example.test', 'password' => 'A long test password 123!', 'password_confirmation' => 'A long test password 123!'];
        $this->postJson('/api/v1/auth/register', $data)->assertConflict()->assertJsonPath('error.code', 'auth.errors.setupRequired');
        User::factory()->create(['email' => 'new@example.test']);
        $this->postJson('/api/v1/auth/register', [...$data, 'email' => ' NEW@example.test '])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/auth/register', [...$data, 'email' => 'other@example.test', 'password_confirmation' => 'mismatch'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_guest_cannot_access_authenticated_or_administrator_endpoints(): void
    {
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
        $this->getJson('/api/v1/admin/status')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_login_rotates_the_session_and_logout_clears_it(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test', 'password' => 'A long test password 123!']);
        $this->getJson('/api/v1/auth/csrf')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $previousId = session()->getId();

        $this->postJson('/api/v1/auth/login', ['email' => 'MEMBER@example.test', 'password' => 'A long test password 123!'])
            ->assertOk()->assertJsonPath('data.id', $user->uuid)->assertJsonPath('data.permissions', []);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($previousId, session()->getId());
        $this->getJson('/api/v1/auth/user')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest();
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_invalid_password_does_not_authenticate(): void
    {
        User::factory()->create(['email' => 'member@example.test']);
        $this->postJson('/api/v1/auth/login', ['email' => 'member@example.test', 'password' => 'Not the password'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'auth.errors.invalidCredentials');
        $this->assertGuest();
    }

    public function test_unknown_email_gets_the_same_error_as_an_invalid_password(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'Not the password'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'auth.errors.invalidCredentials');
        $this->assertGuest();
    }

    public function test_repeated_invalid_credentials_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'Not the password'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'Not the password'])
            ->assertStatus(429)->assertJsonPath('error.code', 'auth.errors.rateLimited');
    }

    public function test_a_member_cannot_gain_administrator_permissions_through_login(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test', 'password' => 'A long test password 123!']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'A long test password 123!', 'permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]])
            ->assertOk()->assertJsonPath('data.permissionRoles.0.name', 'Observer');
        $this->getJson('/api/v1/admin/status')->assertForbidden();
        $this->assertFalse($user->fresh()->isAdministrator());
    }

    public function test_existing_bcrypt_passwords_are_upgraded_only_after_successful_login(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy@example.test',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'password' => password_hash('Legacy password 123!', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'An incorrect password'])->assertUnprocessable();
        $this->assertSame('bcrypt', password_get_info($user->fresh()->password)['algoName']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Legacy password 123!'])->assertOk();
        $this->assertSame('argon2id', password_get_info($user->fresh()->password)['algoName']);
    }

    public function test_administrator_permission_is_enforced_by_the_policy(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($admin)->getJson('/api/v1/admin/status')->assertOk()->assertJsonPath('data.authorized', true);
    }
}
