<?php

namespace Tests\Feature;

use App\Models\PermissionRole;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccessProfileConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_same_version_profile_edits_commit_once_and_revoke_assigned_accounts_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL account row locks.');
        }
        $user = User::factory()->create(['password' => 'Fictional original password 123!']);
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $profile = PermissionRole::create(['name' => 'Race Profile', 'permissions' => ['locations.manage']]);
        $user->permission_role_ids = [$profile->id];
        $user->save();
        Schema::create('profile_test_ready', fn (Blueprint $t) => $t->string('side')->primary());
        $script = tempnam(sys_get_temp_dir(), 'profile-race-');
        $code = <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||config('database.default')!=='pgsql'||!str_contains(config('database.connections.pgsql.database'),'_test'))throw new RuntimeException('Isolated PostgreSQL test database required.');
$user=App\Models\User::findOrFail($argv[1]);$profile=App\Models\PermissionRole::findOrFail($argv[3]);
Illuminate\Support\Facades\DB::table('profile_test_ready')->insert(['side'=>$argv[2]]);
$deadline=microtime(true)+10;
while(Illuminate\Support\Facades\DB::table('profile_test_ready')->count()<2){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timeout.');usleep(20000);}
try{app(App\Services\PermissionRoleAdministration::class)->save($user,['name'=>$profile->name,'permissions'=>[$argv[2]==='A'?'assets.manage':'sensors.manage'],'version'=>1],$profile);echo 'changed';}
catch(App\Exceptions\UserAdministrationException){echo 'revoked';}
PHP;
        file_put_contents($script, $code);
        $processes = [new Process([PHP_BINARY, $script, (string) $admin->id, 'A', $profile->id]), new Process([PHP_BINARY, $script, (string) $admin->id, 'B', $profile->id])];
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
            $this->assertSame(2, $profile->fresh()->version);
            $this->assertContains($profile->fresh()->grants(), [['assets.manage'], ['sensors.manage']]);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            } unlink($script);
            Schema::dropIfExists('profile_test_ready');
        }
    }
}
