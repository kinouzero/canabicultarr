<?php

namespace Tests\Feature;

use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlantTrackingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_manual_actions_record_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL plant row locks.');
        }
        $actors = [User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]), User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]])];
        $site = Site::create(['name' => 'Race Plant Site']);
        $strain = Strain::create(['name' => 'Race Plant Strain']);
        $tracking = app(PlantTracking::class);
        $plant = $tracking->create($actors[0], ['name' => 'Race Plant', 'request_id' => (string) Str::uuid(), 'strain_id' => $strain->id, 'location' => ['type' => 'site', 'id' => $site->id]]);
        Schema::create('plant_test_ready', fn (Blueprint $table) => $table->string('side')->primary());
        $script = tempnam(sys_get_temp_dir(), 'plant-race-');
        file_put_contents($script, <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||config('database.default')!=='pgsql'||!str_contains(config('database.connections.pgsql.database'),'_test'))throw new RuntimeException('Isolated PostgreSQL test database required.');
$actor=App\Models\User::findOrFail($argv[1]);$plant=App\Models\Plant::findOrFail($argv[3]);$tracking=app(App\Services\PlantTracking::class);
Illuminate\Support\Facades\DB::table('plant_test_ready')->insert(['side'=>$argv[2]]);$deadline=microtime(true)+10;
while(Illuminate\Support\Facades\DB::table('plant_test_ready')->count()<2){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timeout.');usleep(20000);}
try{$tracking->action($actor,$plant,['action'=>$argv[4]==='mature'?'start_maturation':'plant','version'=>(int)$argv[5],'request_id'=>(string)Illuminate\Support\Str::uuid(),...($argv[4]==='mature'?['harvest_weight_grams'=>'42.25']:[])]);echo 'changed';}catch(App\Exceptions\PlantException){echo 'conflict';}
PHP);
        $processes = [];
        try {
            foreach (['action', 'mature'] as $kind) {
                if ($kind === 'mature') {
                    // Backdate the configured phase for the isolated manual maturation race.
                    $plant = Plant::findOrFail($plant->id);
                    foreach (['repot_growth', 'repot_flowering', 'harvest', 'transfer_to_rack', 'transfer_to_containers'] as $action) {
                        $plant = $tracking->action($actors[0], $plant, ['action' => $action, 'version' => $plant->version, 'request_id' => (string) Str::uuid()]);
                    }
                    $plant->phase_due_at = now()->subMinute();
                    $plant->save();
                }
                DB::table('plant_test_ready')->delete();
                $processes = [new Process([PHP_BINARY, $script, (string) $actors[0]->id, 'A', $plant->id, $kind, (string) $plant->fresh()->version]), new Process([PHP_BINARY, $script, (string) $actors[1]->id, 'B', $plant->id, $kind, (string) $plant->fresh()->version])];
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
            $this->assertSame('maturation', $plant->fresh()->phase);
            $this->assertSame(8, $plant->fresh()->version);
            $this->assertSame('42.25', $plant->fresh()->harvest_weight_grams);
            $this->assertSame(8, $plant->events()->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            unlink($script);
            Schema::dropIfExists('plant_test_ready');
        }
    }
}
