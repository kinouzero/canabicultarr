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

class EmailChangeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_email_confirmation_commits_once_without_changing_profiles(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL email-change row locks.');
        }
        $user = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('grower')->id]]);
        $profiles = $user->permission_role_ids;
        $token = Str::random(64);
        DB::table('pending_email_changes')->insert(['user_id' => $user->id, 'requested_by' => $user->id, 'user_version' => $user->session_version, 'requester_version' => $user->session_version, 'email' => 'replacement@example.test', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);
        Schema::create('email_change_test_ready', fn (Blueprint $table) => $table->string('side')->primary());
        $script = tempnam(sys_get_temp_dir(), 'email-change-race-');
        file_put_contents($script, <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||config('database.default')!=='pgsql'||!str_contains(config('database.connections.pgsql.database'),'_test'))throw new RuntimeException('Isolated PostgreSQL test database required.');
DB::table('email_change_test_ready')->insert(['side'=>$argv[3]]);
$deadline=microtime(true)+10;
while(DB::table('email_change_test_ready')->count()<2){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timeout.');usleep(20000);}
try{app(App\Services\EmailChange::class)->confirm($argv[1],$argv[2]);echo 'confirmed';}
catch(App\Exceptions\AuthFlowException $e){if($e->errorCode!=='emailChange.invalidLink')throw $e;echo 'rejected';}
PHP);
        $processes = [new Process([PHP_BINARY, $script, 'replacement@example.test', $token, 'A']), new Process([PHP_BINARY, $script, 'replacement@example.test', $token, 'B'])];
        try {
            foreach ($processes as $process) {
                $process->setWorkingDirectory(base_path());
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), 'Email confirmation worker failed.');
            }
            $outcomes = array_map(fn ($process) => $process->getOutput(), $processes);
            sort($outcomes);
            $this->assertSame(['confirmed', 'rejected'], $outcomes);
            $this->assertNotNull($user->fresh()->email_verified_at);
            $this->assertFalse($user->fresh()->email_activation_required);
            $this->assertSame($profiles, $user->fresh()->permission_role_ids);
            $this->assertDatabaseCount('pending_email_changes', 0);
            $this->assertSame('replacement@example.test', $user->fresh()->email);
            $this->assertSame(1, $user->fresh()->session_version);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            unlink($script);
            Schema::dropIfExists('email_change_test_ready');
        }
    }
}
