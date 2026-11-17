<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccountSecurityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_password_changes_commit_once_and_revoke_the_stale_actor(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL account row locks.');
        }
        $user = User::factory()->create(['password' => 'Fictional original password 123!']);
        Schema::create('account_security_test_ready', fn (Blueprint $t) => $t->string('side')->primary());
        $script = tempnam(sys_get_temp_dir(), 'account-security-race-');
        $code = <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||config('database.default')!=='pgsql'||!str_contains(config('database.connections.pgsql.database'),'_test'))throw new RuntimeException('Isolated PostgreSQL test database required.');
$user=App\Models\User::findOrFail($argv[1]);
Illuminate\Support\Facades\DB::table('account_security_test_ready')->insert(['side'=>$argv[2]]);
$deadline=microtime(true)+10;
while(Illuminate\Support\Facades\DB::table('account_security_test_ready')->count()<2){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timeout.');usleep(20000);}
try{app(App\Services\AccountSecurity::class)->apply($user,'Fictional original password 123!','Fictional replacement password '.$argv[2].' 456!');echo 'changed';}
catch(Illuminate\Auth\AuthenticationException){echo 'revoked';}
PHP;
        file_put_contents($script, $code);
        $processes = [new Process([PHP_BINARY, $script, (string) $user->id, 'A']), new Process([PHP_BINARY, $script, (string) $user->id, 'B'])];
        try {
            foreach ($processes as $process) {
                $process->setWorkingDirectory(base_path());
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $results = array_map(fn ($p) => $p->getOutput(), $processes);
            sort($results);
            $this->assertSame(['changed', 'revoked'], $results);
            $fresh = $user->fresh();
            $this->assertSame(1, $fresh->session_version);
            $this->assertTrue(Hash::check('Fictional replacement password A 456!', $fresh->password) || Hash::check('Fictional replacement password B 456!', $fresh->password));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            } unlink($script);
            Schema::dropIfExists('account_security_test_ready');
        }
    }
}
