<?php

namespace Tests\Feature;

use App\Models\OidcIdentity;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultipleProfileMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_membership_migration_preserves_credentials_links_overrides_and_rejects_lossy_rollback(): void
    {
        $role = AccessProfileSeeder::defaultProfile('grower');
        $user = User::factory()->create(['permission_role_ids' => [$role->id], 'permission_grants' => ['inventory.manage'], 'permission_denials' => ['plants.manage']]);
        $link = OidcIdentity::create(['user_id' => $user->id, 'issuer' => 'https://identity.example.test', 'subject' => 'stable']);
        $before = [$user->uuid, $user->password, $user->session_version, $user->permission_grants, $user->permission_denials];
        $migration = require database_path('migrations/2026_11_13_200000_add_multiple_user_profiles.php');
        $migration->down();
        $this->assertSame($role->id, DB::table('users')->where('id', $user->id)->value('permission_role_id'));
        $migration->up();
        $user = $user->fresh();
        $this->assertSame([$role->id], $user->permission_role_ids);
        $this->assertSame($before, [$user->uuid, $user->password, $user->session_version, $user->permission_grants, $user->permission_denials]);
        $this->assertSame($user->id, $link->fresh()->user_id);
        $second = AccessProfileSeeder::defaultProfile('technician')->id;
        $user->permissionRoles()->attach($second);
        try {
            $migration->down();
            $this->fail('Rollback must not discard additional profiles.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('multiple assignments', $exception->getMessage());
        }
        $this->assertSame(2, $user->permissionRoles()->count());
        $user->permissionRoles()->detach($second);
    }
}
