<?php

namespace Tests\Feature;

use App\Mail\AccountActivationMail;
use App\Mail\EmailChangeMail;
use App\Models\PermissionRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdministrativeAccountActivationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Cache::flush();
        Mail::fake();
        config(['app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test', 'auth.local_enabled' => true, 'auth.registration_enabled' => false]);
        $this->admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function input(array $extra = []): array
    {
        return ['name' => 'Invited grower', 'email' => 'invited@example.test', 'permission_role_ids' => [AccessProfileSeeder::defaultProfile('grower')->id], 'password' => 'A fictional grower password 123!', 'password_confirmation' => 'A fictional grower password 123!', 'is_active' => true, 'locale' => 'fr', ...$extra];
    }

    private function edit(User $user, array $extra = []): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'permission_role_ids' => $user->permission_role_ids, 'is_active' => $user->is_active, 'locale' => 'fr', ...$extra];
    }

    private function deliver(bool $emailChange = false): string
    {
        Mail::fake();
        $job = Queue::connection('database')->pop('account-activation');
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();
        $token = '';
        Mail::assertSent($emailChange ? EmailChangeMail::class : AccountActivationMail::class, function ($mail) use (&$token, $emailChange) {
            $token = explode($emailChange ? '#confirm-email=' : '#activate-account=', $emailChange ? $mail->confirmationUrl : $mail->activationUrl)[1];

            return strlen($token) === 64;
        });

        return $token;
    }

    public function test_administrative_creation_preserves_access_and_requires_single_use_email_confirmation(): void
    {
        $site = Site::create(['name' => 'Allowed grow site']);
        $data = $this->input(['permission_grants' => ['inventory.manage'], 'permission_denials' => ['strains.manage'], 'location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $this->actingAs($this->admin)->postJson('/api/v1/users', $data)->assertCreated()->assertJsonPath('data.emailActivationRequired', true)->assertJsonPath('data.permissionRoles.0.id', $data['permission_role_ids'][0])->assertJsonPath('data.permissionGrants', ['inventory.manage'])->assertJsonPath('data.permissionDenials', ['strains.manage'])->assertJsonPath('data.locationAccess.0.id', $site->id)->assertJsonMissingPath('data.password');
        $user = User::where('email', $data['email'])->sole();
        $this->assertNull($user->email_verified_at);
        Mail::assertNothingSent();
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString($data['password'], $payload);
        $token = $this->deliver();
        $this->assertStringNotContainsString($token, $payload);
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $data['password']])->assertForbidden()->assertJsonPath('error.code', 'accountActivation.required');
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => $user->email, 'token' => $token])->assertNoContent();
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => $user->email, 'token' => $token])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $data['password']])->assertOk();
        $this->assertSame($data['permission_role_ids'], $user->fresh()->permission_role_ids);
        $this->assertTrue($this->admin->fresh()->isAdministrator());
        $this->assertFalse($this->admin->fresh()->email_activation_required);
    }

    public function test_inactive_accounts_receive_a_fresh_link_on_reactivation_and_pending_email_changes_rotate_it(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/users', $this->input(['is_active' => false]))->assertCreated()->assertJsonPath('data.emailActivationRequired', true);
        $user = User::where('email', 'invited@example.test')->sole();
        $this->assertDatabaseCount('jobs', 0);
        $this->patchJson('/api/v1/users/'.$user->uuid, $this->edit($user, ['is_active' => true]))->assertOk();
        $token = $this->deliver();
        $user->refresh();
        $this->patchJson('/api/v1/users/'.$user->uuid, $this->edit($user, ['email' => 'corrected@example.test']))->assertOk();
        $replacement = $this->deliver(true);
        $this->assertNotSame($token, $replacement);
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => 'invited@example.test', 'token' => $token])->assertUnprocessable();
        $this->postJson('/api/v1/auth/activation/confirm', ['email' => 'corrected@example.test', 'token' => $token])->assertUnprocessable();
        $this->postJson('/api/v1/auth/email-change/confirm', ['email' => 'corrected@example.test', 'token' => $replacement])->assertNoContent();
    }

    public function test_configuration_failures_never_create_partial_accounts_or_modify_existing_access(): void
    {
        config(['mail.default' => 'log']);
        $this->actingAs($this->admin)->postJson('/api/v1/users', $this->input())->assertStatus(503)->assertJsonPath('error.code', 'accountActivation.unavailable');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_activation_tokens', 0);
        $this->assertDatabaseCount('jobs', 0);
        config(['mail.default' => 'smtp', 'auth.local_enabled' => false, 'oidc.enabled' => true]);
        $this->postJson('/api/v1/users', $this->input())->assertConflict()->assertJsonPath('error.code', 'users.errors.localCreationDisabled');
        $this->patchJson('/api/v1/users/'.$this->admin->uuid, $this->edit($this->admin, ['name' => 'Existing OIDC administrator']))->assertOk();
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($this->admin->fresh()->isAdministrator());
    }

    public function test_queue_failure_rolls_back_user_profiles_and_activation_tokens(): void
    {
        Queue::shouldReceive('connection')->withNoArgs()->andThrow(new \RuntimeException('Private queue credentials'));
        $this->actingAs($this->admin)->postJson('/api/v1/users', $this->input())->assertStatus(503)->assertExactJson(['error' => ['code' => 'accountActivation.unavailable']]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('permission_role_user', 1);
        $this->assertDatabaseCount('account_activation_tokens', 0);
    }

    public function test_pending_administrators_cannot_replace_the_last_confirmed_administrator(): void
    {
        $profile = PermissionRole::create(['name' => 'Existing administrator', 'permissions' => ['administration.view']]);
        $this->admin->permissionRoles()->sync([$profile->id]);
        $this->admin->refresh();
        $this->actingAs($this->admin)->postJson('/api/v1/users', $this->input(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]))->assertCreated();
        $this->patchJson('/api/v1/users/'.$this->admin->uuid, $this->edit($this->admin, ['permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id]]))->assertConflict()->assertJsonPath('error.code', 'users.errors.lastAdministrator');
        $this->patchJson('/api/v1/permission-roles/'.$profile->id, ['name' => $profile->name, 'permissions' => [], 'version' => $profile->version])->assertConflict()->assertJsonPath('error.code', 'users.errors.lastAdministrator');
        $this->assertTrue($this->admin->fresh()->isAdministrator());
        $pending = User::where('email', 'invited@example.test')->sole();
        $this->patchJson('/api/v1/users/'.$pending->uuid, $this->edit($pending, ['permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id]]))->assertOk();
    }

    public function test_pending_account_reactivation_rolls_back_when_delivery_cannot_be_queued(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/users', $this->input(['is_active' => false]))->assertCreated();
        $user = User::where('email', 'invited@example.test')->sole();
        config(['mail.default' => 'log']);
        $this->patchJson('/api/v1/users/'.$user->uuid, $this->edit($user, ['is_active' => true]))->assertStatus(503);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->fresh()->session_version);
        $this->assertDatabaseCount('account_activation_tokens', 0);
    }
}
