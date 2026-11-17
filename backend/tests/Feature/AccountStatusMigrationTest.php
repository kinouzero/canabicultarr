<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_administrator_keeps_identity_and_credentials_with_a_valid_legacy_session_version(): void
    {
        $administrator = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $migration = require database_path('migrations/2026_11_06_030000_add_account_status.php');
        $migration->down();
        $migration->up();

        $preserved = $administrator->fresh();
        $this->assertSame($administrator->uuid, $preserved->uuid);
        $this->assertSame($administrator->password, $preserved->password);
        $this->assertTrue($preserved->isAdministrator());
        $this->assertTrue($preserved->is_active);
        $this->assertSame(0, $preserved->session_version);
    }
}
