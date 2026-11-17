<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_profiles_replace_roles_without_losing_credentials_access_or_related_records(): void
    {
        $migration = require database_path('migrations/2026_11_10_210000_unify_user_access_profiles.php');
        $multiple = require database_path('migrations/2026_11_13_200000_add_multiple_user_profiles.php');
        $multiple->down();
        $migration->down();
        $adminId = DB::table('users')->insertGetId([...User::factory()->raw(), 'uuid' => (string) Str::uuid(), 'role' => 'admin', 'permission_role_id' => null]);
        $memberId = DB::table('users')->insertGetId([...User::factory()->raw(), 'uuid' => (string) Str::uuid(), 'permission_role_id' => AccessProfileSeeder::defaultProfile('grower')->id, 'permission_grants' => json_encode(['inventory.manage'])]);
        $before = DB::table('users')->where('id', $adminId)->first();
        $snapshot = [$before->uuid, $before->password, $before->email, (int) $before->session_version];
        $migration->up();
        $multiple->up();
        $admin = User::findOrFail($adminId);
        $member = User::findOrFail($memberId);
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue($admin->isAdministrator());
        $this->assertSame($snapshot, [$admin->uuid, $admin->password, $admin->email, $admin->session_version]);
        $this->assertSame(['inventory.manage', 'plants.manage'], $member->fresh()->effectiveOperationalPermissions());
        $this->assertSame(2, User::count());
        $observer = User::factory()->create();
        $this->assertSame('Observer', $observer->permissionRoles->first()->name);
    }
}
