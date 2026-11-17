<?php

namespace Tests\Feature;

use App\Exceptions\AuthFlowException;
use App\Models\ApplicationSetup;
use App\Models\OidcIdentity;
use App\Models\User;
use App\Services\AdministratorSetup;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentAdministratorSetupTest extends TestCase
{
    use DatabaseMigrations;

    public function test_local_and_oidc_bootstrap_can_only_create_one_administrator(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent setup row locking requires PostgreSQL.');
        }
        $token = app(AdministratorSetup::class)->issueToken();
        $database = DB::connection()->getDatabaseName();
        $operations = [];
        foreach (['local', 'oidc'] as $mode) {
            $operations[] = static function () use ($mode, $token, $database) {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database) {
                    return 'wrong_test_environment';
                }
                config(['queue.default' => 'database', 'app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test']);
                try {
                    $setup = app(AdministratorSetup::class);
                    if ($mode === 'local') {
                        $setup->complete(['token' => $token, 'name' => 'Local administrator', 'email' => 'local@example.test', 'password' => 'A long fictional password 123!']);
                    } else {
                        $setup->completeOidc(hash('sha256', $token), 'https://identity.example.test', 'verified-subject', 'oidc@example.test', 'OIDC administrator');
                    }

                    return 'created';
                } catch (AuthFlowException $exception) {
                    return $exception->errorCode;
                }
            };
        }
        $results = Concurrency::driver('process')->run($operations, timeout: 20);
        sort($results);
        $this->assertSame(['auth.errors.setupClosed', 'created'], $results);
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue(User::sole()->isAdministrator());
        $this->assertNotNull(ApplicationSetup::findOrFail(1)->completed_at);
        $this->assertNull(ApplicationSetup::findOrFail(1)->token_hash);
        $this->assertSame(User::sole()->password === null ? 1 : 0, OidcIdentity::count());
        // Remove only the isolated bootstrap fixture before schema rollback.
        User::sole()->delete();
    }
}
