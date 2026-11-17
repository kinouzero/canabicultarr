<?php

namespace Tests\Feature;

use App\Enums\DelegatedPermission;
use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\MeasurementType;
use App\Models\OidcIdentity;
use App\Models\PushSubscription;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\HomeAssistantClient;
use App\Services\RemoteSensorImport;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class DelegatedPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        config(['app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test']);
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function member(array $grants = []): User
    {
        return User::factory()->create(['permission_grants' => $grants]);
    }

    private function input(User $target, array $extra = []): array
    {
        return ['name' => $target->name, 'email' => $target->email, 'permission_role_ids' => $target->permission_role_ids, 'is_active' => $target->is_active, ...$extra];
    }

    private function grant(User $admin, User $target, array $grants): User
    {
        return app(UserAdministration::class)->update($admin, $target, $this->input($target, ['permission_grants' => $grants]));
    }

    public function test_default_members_keep_baseline_reads_without_operational_writes(): void
    {
        $member = $this->member();
        $this->actingAs($member)->getJson('/api/v1/auth/user')->assertOk()->assertJsonPath('data.permissions', []);
        foreach (['sites', 'assets', 'sensors', 'measurement-types', 'inventory/items', 'alert-rules'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertOk();
            $this->postJson('/api/v1/'.$path, [])->assertForbidden();
        }
        $this->getJson('/api/v1/integrations/home-assistant')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_each_permission_is_independent_in_policies_and_http_validation(): void
    {
        $mapping = ['locations.manage' => Site::class, 'assets.manage' => Asset::class, 'sensors.manage' => Sensor::class, 'inventory.manage' => InventoryItem::class, 'alerts.manage' => AlertRule::class];
        $routes = ['locations.manage' => 'sites', 'assets.manage' => 'assets', 'sensors.manage' => 'sensors', 'inventory.manage' => 'inventory/items', 'alerts.manage' => 'alert-rules'];
        foreach (DelegatedPermission::cases() as $permission) {
            $member = $this->member([$permission->value]);
            $this->actingAs($member)->getJson('/api/v1/auth/user')->assertJsonPath('data.permissions', [$permission->value]);
            foreach ($mapping as $key => $model) {
                $this->assertSame($key === $permission->value, Gate::forUser($member)->allows('create', $model));
                $response = $this->postJson('/api/v1/'.$routes[$key], []);
                $key === $permission->value ? $response->assertUnprocessable() : $response->assertForbidden();
            }
            $this->assertSame($permission === DelegatedPermission::Sensors, Gate::forUser($member)->allows('create', MeasurementType::class));
            $status = $this->getJson('/api/v1/integrations/home-assistant');
            $permission === DelegatedPermission::HomeAssistant ? $status->assertOk() : $status->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_all_delegations_do_not_grant_account_or_global_notification_administration(): void
    {
        $member = $this->member(array_values(array_diff(DelegatedPermission::values(), ['administration.view'])));
        $this->actingAs($member)->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', [])->assertForbidden();
        $this->patchJson('/api/v1/users/'.$member->uuid, $this->input($member, ['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]))->assertForbidden();
        $this->patchJson('/api/v1/notifications/settings', [])->assertForbidden();
        $this->getJson('/api/v1/admin/status')->assertForbidden();
        $this->getJson('/api/v1/account/security')->assertOk();
        $this->assertFalse($member->fresh()->isAdministrator());
    }

    public function test_active_admins_keep_all_operational_rights_and_inactive_accounts_get_none(): void
    {
        $admin = $this->admin();
        $inactive = $this->member(array_values(array_diff(DelegatedPermission::values(), ['administration.view'])));
        $inactive->is_active = false;
        foreach (DelegatedPermission::cases() as $permission) {
            $this->assertTrue($admin->hasOperationalPermission($permission));
            $this->assertFalse($inactive->hasOperationalPermission($permission));
        }
        $this->actingAs($admin)->getJson('/api/v1/auth/user')->assertJsonPath('data.permissions', (function () {
            $values = DelegatedPermission::values();
            sort($values);

            return $values;
        })());
        $inactive->save();
        $this->actingAs($inactive)->getJson('/api/v1/assets')->assertUnauthorized();
    }

    public function test_admin_creates_a_member_with_valid_grants_and_raw_storage_is_not_serialized(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Delegated Member', 'email' => 'delegated@example.test', 'permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id], 'password' => 'A fictional delegated password 123!', 'password_confirmation' => 'A fictional delegated password 123!', 'permission_grants' => ['sensors.manage', 'assets.manage']])->assertCreated()->assertJsonPath('data.permissionGrants', ['assets.manage', 'sensors.manage']);
        $member = User::where('email', 'delegated@example.test')->sole();
        $this->assertSame(['assets.manage', 'sensors.manage'], $member->permission_grants);
        $this->assertArrayNotHasKey('permission_grants', $member->toArray());
        $this->assertSame(0, $member->session_version);
        $response->assertJsonMissingPath('data.password');
        Mail::assertNothingSent();
    }

    public function test_invalid_privileged_duplicate_associative_or_nonarray_grants_are_rejected(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        foreach ([['invalid.permission'], ['notifications.manage'], ['unknown.manage'], ['assets.manage', 'assets.manage'], ['first' => 'assets.manage'], null, 'assets.manage', [str_repeat('x', 500)], [123], array_fill(0, 7, 'assets.manage')] as $grants) {
            Cache::flush();
            $this->actingAs($admin)->patchJson('/api/v1/users/'.$member->uuid, $this->input($member, ['permission_grants' => $grants]))->assertUnprocessable();
            $this->assertSame([], $member->fresh()->delegatedPermissions());
            $this->assertSame(0, $member->fresh()->session_version);
        }
    }

    public function test_omitted_grants_preserve_them_and_equivalent_order_does_not_revoke_sessions(): void
    {
        $admin = $this->admin();
        $member = $this->member(['assets.manage', 'sensors.manage']);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$member->uuid, $this->input($member, ['name' => 'New fictional name']))->assertOk();
        $this->patchJson('/api/v1/users/'.$member->uuid, $this->input($member->fresh(), ['permission_grants' => ['sensors.manage', 'assets.manage']]))->assertOk();
        $this->assertSame(['assets.manage', 'sensors.manage'], $member->fresh()->delegatedPermissions());
        $this->assertSame(0, $member->fresh()->session_version);
    }

    public function test_permission_changes_revoke_owned_sessions_push_and_reset_artifacts_without_altering_identity(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $other = $this->member();
        $member->remember_token = 'fictional-remember';
        $member->save();
        $hash = $member->password;
        OidcIdentity::create(['user_id' => $member->id, 'issuer' => 'https://identity.example.test/application/o/garden/', 'subject' => 'fictional-delegated-subject']);
        foreach ([$member, $other] as $owner) {
            DB::table('sessions')->insert(['id' => 'permission-session-'.$owner->id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp]);
            Password::broker()->createToken($owner);
            $endpoint = 'https://fcm.googleapis.com/fcm/send/fictional-permissions-'.$owner->id;
            PushSubscription::create(['user_id' => $owner->id, 'endpoint_hash' => hash('sha256', $endpoint), 'session_hash' => hash('sha256', 'fictional-session'), 'endpoint' => $endpoint, 'keys' => ['p256dh' => 'fictional-key', 'auth' => 'fictional-auth'], 'vapid_key_hash' => hash('sha256', 'fictional-vapid'), 'user_version' => 0]);
        }
        config(['session.driver' => 'database']);
        $this->grant($admin, $member, ['assets.manage']);
        $fresh = $member->fresh();
        $this->assertSame(1, $fresh->session_version);
        $this->assertNull($fresh->remember_token);
        $this->assertSame($hash, $fresh->password);
        $this->assertFalse($fresh->isAdministrator());
        $this->assertSame($member->email, $fresh->email);
        $this->assertDatabaseHas('oidc_identities', ['user_id' => $member->id]);
        foreach (['sessions', 'push_subscriptions'] as $table) {
            $this->assertDatabaseMissing($table, ['user_id' => $member->id]);
            $this->assertDatabaseHas($table, ['user_id' => $other->id]);
        }
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $member->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);
        $this->assertSame(0, $other->fresh()->session_version);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_revoked_permission_is_rechecked_inside_service_even_with_a_stale_actor(): void
    {
        $admin = $this->admin();
        $member = $this->member(['assets.manage']);
        $this->grant($admin, $member, []);
        try {
            app(AssetAdministration::class)->save($member, ['name' => 'Forbidden stale write']);
            $this->fail('Stale grant accepted.');
        } catch (AuthenticationException|AuthorizationException) {
            $this->assertDatabaseCount('assets', 0);
        }
    }

    public function test_permission_change_invalidates_preexisting_session_version(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $this->grant($admin, $member, ['inventory.manage']);
        $this->actingAs($member->fresh())->withSession(['auth_version' => 0])->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_member_cannot_assign_own_or_foreign_permissions_even_when_all_granted(): void
    {
        $admin = $this->admin();
        $member = $this->member(array_values(array_diff(DelegatedPermission::values(), ['administration.view'])));
        foreach ([$member, $admin] as $target) {
            $this->actingAs($member)->patchJson('/api/v1/users/'.$target->uuid, $this->input($target, ['permission_grants' => []]))->assertForbidden();
        }
        $this->assertSame(array_values(array_diff(DelegatedPermission::values(), ['administration.view'])), array_values(array_filter(array_values(array_diff(DelegatedPermission::values(), ['administration.view'])), fn ($v) => in_array($v, $member->fresh()->delegatedPermissions(), true))));
    }

    public function test_grants_do_not_replace_last_administrator_protection_and_promoting_clears_dormant_grants(): void
    {
        $admin = $this->admin();
        $member = $this->member(['assets.manage']);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$admin->uuid, $this->input($admin, ['permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id], 'permission_grants' => array_values(array_diff(DelegatedPermission::values(), ['administration.view']))]))->assertStatus(409);
        $this->patchJson('/api/v1/users/'.$member->uuid, $this->input($member, ['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id], 'permission_grants' => ['assets.manage']]))->assertOk()->assertJsonPath('data.permissionGrants', ['assets.manage']);
        $this->assertSame(['assets.manage'], $member->fresh()->permission_grants);
    }

    public function test_unknown_storage_values_are_ignored_without_becoming_privileges(): void
    {
        $member = $this->member(['invalid.permission', 'unknown.manage', 'assets.manage', 'assets.manage']);
        $this->assertSame(['assets.manage'], $member->delegatedPermissions());
        $this->assertFalse(Gate::forUser($member)->allows('viewAdministration', User::class));
    }

    public function test_delegated_members_can_create_and_update_real_manual_records_in_their_scope(): void
    {
        $member = $this->member(['locations.manage', 'assets.manage', 'sensors.manage', 'inventory.manage', 'alerts.manage']);
        $this->actingAs($member);
        $site = $this->postJson('/api/v1/sites', ['name' => 'Delegated Test Site'])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/sites/'.$site, ['name' => 'Delegated Updated Site'])->assertOk();
        $device = $this->postJson('/api/v1/assets', ['name' => 'Manual Delegated Device', 'status' => 'active'])->assertCreated()->json('data.id');
        $type = $this->postJson('/api/v1/measurement-types', ['name' => 'Delegated Type', 'code' => 'delegated_type', 'unit' => 'test', 'decimal_places' => 2])->assertCreated()->json('data.id');
        $sensor = $this->postJson('/api/v1/sensors', ['name' => 'Delegated Sensor', 'device_id' => $device, 'measurement_type_id' => $type, 'channel_key' => 'probe'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/alert-rules', ['name' => 'Delegated Missing Rule', 'kind' => 'missing', 'sensor_id' => $sensor, 'severity' => 'warning', 'delay_seconds' => 120])->assertCreated()->assertJsonPath('data.canManage', true);
        $item = $this->postJson('/api/v1/inventory/items', ['name' => 'Delegated Supply', 'unit' => 'kg'])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/items/'.$item['id'].'/movements', ['kind' => 'receipt', 'quantity' => '1.500', 'reason' => 'Delegated receipt', 'version' => $item['version'], 'request_id' => (string) Str::uuid(), 'destination' => ['id' => StorageLocation::create(['name' => 'Delegated storage'])->id]])->assertCreated();
        $this->assertDatabaseCount('home_assistant_links', 0);
        $this->assertDatabaseCount('assets', 1);
        Http::assertNothingSent();
    }

    public function test_home_assistant_permission_imports_without_manual_placeholder_or_other_grants(): void
    {
        config(['home_assistant.url' => 'https://ha.example.test', 'home_assistant.token' => 'fictional-ha-token']);
        // The adapter itself is mocked: importing still uses the real transactional database service.
        $client = \Mockery::mock(HomeAssistantClient::class)->makePartial();
        $client->shouldReceive('states')->once()->andReturn([['entity_id' => 'sensor.delegated_temperature', 'state' => '20', 'attributes' => ['friendly_name' => 'Delegated Temperature', 'unit_of_measurement' => '°C', 'device_class' => 'temperature']]]);
        $client->shouldReceive('devices')->once()->andReturn(['sensor.delegated_temperature' => ['device_id' => 'fictional-delegated-device', 'name' => 'Delegated HA Device']]);
        $client->shouldReceive('instanceKey')->once()->andReturn(hash('sha256', 'fictional-instance'));
        $service = new RemoteSensorImport($client);
        $member = $this->member(['integrations.manage']);
        $result = $service->import($member, ['sensor.delegated_temperature']);
        $this->assertSame(1, $result['devicesCreated']);
        $this->assertSame(1, $result['sensorsCreated']);
        $this->assertFalse($member->hasOperationalPermission(DelegatedPermission::Assets));
        Http::assertNothingSent();
    }

    public function test_audit_records_changed_field_names_without_grant_lists_or_personal_values(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        Log::spy();
        $this->grant($admin, $member, ['assets.manage']);
        Log::shouldHaveReceived('notice')->with('user.updated', ['actor_uuid' => $admin->uuid, 'user_uuid' => $member->uuid, 'fields' => ['permission_grants']])->once();
    }

    public function test_additive_migration_preserves_accounts_credentials_roles_and_null_defaults(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $before = [[...$admin->fresh()->getAttributes(), 'permission_role_ids' => $admin->permission_role_ids], [...$member->fresh()->getAttributes(), 'permission_role_ids' => $member->permission_role_ids]];
        $migration = require database_path('migrations/2026_11_07_030000_add_delegated_user_permissions.php');
        $migration->down();
        $migration->up();
        foreach ([$admin, $member] as $i => $user) {
            $after = $user->fresh();
            foreach (['uuid', 'password', 'permission_role_ids', 'session_version', 'email'] as $field) {
                $this->assertSame($before[$i][$field], ($field === 'permission_role_ids' ? $after->permission_role_ids : $after->getAttributes()[$field]));
            }
            $this->assertSame([], $after->delegatedPermissions());
        }
    }
}
