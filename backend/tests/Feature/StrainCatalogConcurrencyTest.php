<?php

namespace Tests\Feature;

use App\Models\LifecycleSetting;
use App\Models\Strain;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StrainCatalogConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_different_administrators_cannot_commit_two_edits_with_the_same_version(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL catalog row locks.');
        }
        $actors = [User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]), User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]])];
        $record = Strain::create(['name' => 'Race Reference']);
        Schema::create('catalog_test_ready', fn (Blueprint $table) => $table->string('side')->primary());
        $script = tempnam(sys_get_temp_dir(), 'catalog-race-');
        file_put_contents($script, <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||config('database.default')!=='pgsql'||!str_contains(config('database.connections.pgsql.database'),'_test'))throw new RuntimeException('Isolated PostgreSQL test database required.');
$actor=App\Models\User::findOrFail($argv[1]);$record=App\Models\Strain::findOrFail($argv[3]);$catalog=app(App\Services\StrainCatalog::class);$durations=$catalog->settings()->durations;
Illuminate\Support\Facades\DB::table('catalog_test_ready')->insert(['side'=>$argv[2]]);$deadline=microtime(true)+10;
while(Illuminate\Support\Facades\DB::table('catalog_test_ready')->count()<2){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timeout.');usleep(20000);}
try{if($argv[4]==='settings'){$durations['growth']=$argv[2]==='A'?28:35;$catalog->saveSettings($actor,['version'=>1,'durations'=>$durations]);}else{$catalog->save($actor,['version'=>1,'notes'=>$argv[2]],$record);}echo 'changed';}
catch(App\Exceptions\CatalogException){echo 'conflict';}
PHP);
        $processes = [];
        try {
            foreach (['strain', 'settings'] as $kind) {
                DB::table('catalog_test_ready')->delete();
                $processes = [new Process([PHP_BINARY, $script, (string) $actors[0]->id, 'A', $record->id, $kind]), new Process([PHP_BINARY, $script, (string) $actors[1]->id, 'B', $record->id, $kind])];
                foreach ($processes as $process) {
                    $process->setWorkingDirectory(base_path());
                    $process->start();
                }
                foreach ($processes as $process) {
                    $process->wait();
                    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                }
                $results = array_map(fn ($process) => $process->getOutput(), $processes);
                sort($results);
                $this->assertSame(['changed', 'conflict'], $results);
            }
            $this->assertSame(2, $record->fresh()->version);
            $this->assertSame(2, LifecycleSetting::findOrFail(1)->version);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            unlink($script);
            Schema::dropIfExists('catalog_test_ready');
        }
    }
}
