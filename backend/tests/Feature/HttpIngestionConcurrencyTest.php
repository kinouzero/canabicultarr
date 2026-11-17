<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\User;
use App\Services\HttpSourceAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HttpIngestionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_http_requests_store_one_immutable_sample(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL account row locks.');
        }
        $user = User::factory()->create(['password' => 'Fictional original password 123!']);
        $user->permission_role_ids = [AccessProfileSeeder::defaultProfile('administrator')->id];
        $user->save();
        $asset = Asset::create(['name' => 'Race HTTP Device', 'status' => 'active']);
        $type = MeasurementType::create(['name' => 'Race HTTP Type', 'code' => 'race_http_type', 'unit' => 'test']);
        $sensor = new Sensor(['name' => 'Race HTTP Sensor', 'channel_key' => 'probe']);
        $sensor->asset_id = $asset->id;
        $sensor->measurement_type_id = $type->id;
        $sensor->save();
        [$source, $token] = app(HttpSourceAdministration::class)->apply($user, ['sensor_id' => $sensor->id, 'expires_days' => 90]);
        $time = now()->subSecond()->startOfSecond()->toISOString();
        Schema::create('http_ingestion_test_ready', fn (Blueprint $t) => $t->string('side')->primary());
        $script = tempnam(sys_get_temp_dir(), 'http-ingestion-race-');
        $code = <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||config('database.default')!=='pgsql'||!str_contains(config('database.connections.pgsql.database'),'_test'))throw new RuntimeException('Isolated PostgreSQL test database required.');
$source=App\Models\HttpMeasurementSource::findOrFail($argv[1]);
Illuminate\Support\Facades\DB::table('http_ingestion_test_ready')->insert(['side'=>$argv[2]]);
$deadline=microtime(true)+10;
while(Illuminate\Support\Facades\DB::table('http_ingestion_test_ready')->count()<2){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timeout.');usleep(20000);}
echo app(App\Services\HttpMeasurementIngestion::class)->ingest($source,$argv[3],['value'=>'1.123456789','unit'=>'test','observed_at'=>$argv[4]]);
PHP;
        file_put_contents($script, $code);
        $processes = [new Process([PHP_BINARY, $script, $source->id, 'A', $token, $time]), new Process([PHP_BINARY, $script, $source->id, 'B', $token, $time])];
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
            $this->assertSame(['duplicate', 'stored'], $results);
            $this->assertDatabaseCount('measurements', 1);
            $reading = Measurement::sole();
            $this->assertSame('1.123456789', $reading->value);
            $this->assertSame($source->id, $reading->http_measurement_source_id);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            } unlink($script);
            Schema::dropIfExists('http_ingestion_test_ready');
            Measurement::where('http_measurement_source_id', $source->id)->delete();
        }
    }
}
