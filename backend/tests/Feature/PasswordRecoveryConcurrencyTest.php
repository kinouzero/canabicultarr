<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PasswordRecoveryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_resets_consume_the_same_token_exactly_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL account row locks.');
        }
        $user = User::factory()->create(['password' => 'Fictional original password 123!']);
        $token = Password::broker()->createToken($user);
        Schema::create('recovery_test_ready', fn (Blueprint $t) => $t->string('side')->primary());
        $script = tempnam(sys_get_temp_dir(), 'recovery-race-');
        $code = <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||config('database.default')!=='pgsql'||!str_contains(config('database.connections.pgsql.database'),'_test'))throw new RuntimeException('Isolated PostgreSQL test database required.');
$user=App\Models\User::findOrFail($argv[1]);
config(['queue.default'=>'database','password_recovery.enabled'=>true,'password_recovery.url'=>'https://garden.example.test/','mail.default'=>'smtp','mail.mailers.smtp.host'=>'smtp.example.test','mail.from.address'=>'garden@example.test']);
Illuminate\Support\Facades\DB::table('recovery_test_ready')->insert(['side'=>$argv[2]]);
$deadline=microtime(true)+10;
while(Illuminate\Support\Facades\DB::table('recovery_test_ready')->count()<2){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timeout.');usleep(20000);}
try{app(App\Services\PasswordRecovery::class)->reset($user->email,$argv[3],'Fictional replacement password '.$argv[2].' 456!');echo 'changed';}
catch(App\Exceptions\AuthFlowException $error){if($error->errorCode!=='passwordRecovery.invalidLink')throw $error;echo 'revoked';}
PHP;
        file_put_contents($script, $code);
        $processes = [new Process([PHP_BINARY, $script, (string) $user->id, 'A', $token]), new Process([PHP_BINARY, $script, (string) $user->id, 'B', $token])];
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
            Schema::dropIfExists('recovery_test_ready');
        }
    }
}
