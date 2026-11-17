<?php

namespace Tests\Feature;

use App\Models\OidcIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PasswordlessAccountMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_password_nullability_changes_preserve_users_identities_and_constraints(): void
    {
        $user = User::factory()->create();
        $identity = OidcIdentity::create(['user_id' => $user->id, 'issuer' => 'https://identity.example.test', 'subject' => 'stable-subject']);
        $hash = $user->password;
        $migration = require database_path('migrations/2026_11_13_190000_allow_passwordless_oidc_accounts.php');
        $migration->down();
        $migration->up();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($user->id, $identity->fresh()->user_id);
        DB::table('users')->where('id', $user->id)->update(['password' => null]);
        try {
            $migration->down();
            $this->fail('Passwordless accounts must prevent rollback to required passwords.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('passwordless accounts', $exception->getMessage());
        }
        $this->assertNull($user->fresh()->password);
        // Restore the fixture so the full schema teardown can require passwords again.
        DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
        $this->expectException(QueryException::class);
        OidcIdentity::create(['user_id' => 999999999, 'issuer' => 'https://identity.example.test', 'subject' => 'invalid-owner']);
    }
}
