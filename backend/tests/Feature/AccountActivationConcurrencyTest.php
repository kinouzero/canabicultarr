<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccountActivationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_activation_confirms_once_without_changing_profiles(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL activation row locks.');
        }
        $user = User::factory()->create(['email_activation_required' => true, 'email_verified_at' => null, 'permission_role_ids' => [AccessProfileSeeder::defaultProfile('grower')->id]]);
        $profiles = $user->permission_role_ids;
        $token = Str::random(64);
        DB::table('account_activation_tokens')->insert(['user_id' => $user->id, 'email' => $user->email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);
        Schema::create('activation_test_ready', fn (Blueprint $table) => $table->string('side')->primary());
        $script = tempnam(sys_get_temp_dir(), 'activation-race-');
        file_put_contents($script, <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||config('database.default')!=='pgsql'||!str_contains(config('database.connections.pgsql.database'),'_test'))throw new RuntimeException('Isolated PostgreSQL test database required.');
DB::table('activation_test_ready')->insert(['side'=>$argv[3]]);
$deadline=microtime(true)+10;
while(DB::table('activation_test_ready')->count()<2){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timeout.');usleep(20000);}
try{app(App\Services\AccountActivation::class)->confirm($argv[1],$argv[2]);echo 'confirmed';}
catch(App\Exceptions\AuthFlowException $e){if($e->errorCode!=='accountActivation.invalidLink')throw $e;echo 'rejected';}
PHP);
        $processes = [new Process([PHP_BINARY, $script, $user->email, $token, 'A']), new Process([PHP_BINARY, $script, $user->email, $token, 'B'])];
        try {
            foreach ($processes as $process) {
                $process->setWorkingDirectory(base_path());
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), 'Activation worker failed.');
            }
            $outcomes = array_map(fn ($process) => $process->getOutput(), $processes);
            sort($outcomes);
            $this->assertSame(['confirmed', 'rejected'], $outcomes);
            $this->assertNotNull($user->fresh()->email_verified_at);
            $this->assertFalse($user->fresh()->email_activation_required);
            $this->assertSame($profiles, $user->fresh()->permission_role_ids);
            $this->assertDatabaseCount('account_activation_tokens', 0);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            unlink($script);
            Schema::dropIfExists('activation_test_ready');
        }
    }
}
