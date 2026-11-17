<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\MeasurementType;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\AssetAdministration;
use App\Services\NotificationAdministration;
use App\Services\NotificationConfiguration;
use App\Services\SensorAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NotificationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_postgresql_competing_workers_claim_one_delivery_and_send_outside_the_transaction(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL row lock race.');
        }
        Http::preventStrayRequests();
        $config = ['url' => 'https://notify.example.test/hook', 'token' => 'fixture', 'format' => 'generic', 'topic' => '', 'tag' => '', 'apprise_urls' => ''];
        config(['alert_notifications.webhook' => $config]);
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        app(NotificationAdministration::class)->saveSettings($admin, [...NotificationConfiguration::DEFAULTS, 'webhook_enabled' => true]);
        $device = app(AssetAdministration::class)->save($admin, ['name' => 'Fictional Race Device']);
        $type = MeasurementType::create(['name' => 'Fictional Race Type', 'code' => 'notification_race', 'unit' => 'test']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => 'Fictional Race Probe', 'device_id' => $device->id, 'measurement_type_id' => $type->id, 'channel_key' => 'probe']);
        $rule = AlertRule::create(['sensor_id' => $sensor->id, 'name' => 'Fictional Race Rule', 'kind' => 'missing', 'severity' => 'warning', 'delay_seconds' => 120, 'activated_at' => now()->subHour()]);
        app(AlertEvaluator::class)->evaluate($rule);
        $delivery = NotificationDelivery::sole();
        Schema::create('notification_test_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('transaction_level');
        });
        $script = tempnam(sys_get_temp_dir(), 'notification-race-');
        $code = <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'pgsql' || ! str_contains(config('database.connections.pgsql.database'), '_test')) throw new RuntimeException('Isolated PostgreSQL test database required.');
config(['alert_notifications.webhook' => ['url'=>'https://notify.example.test/hook','token'=>'fixture','format'=>'generic','topic'=>'','tag'=>'','apprise_urls'=>'']]);
$app->bind(App\Services\NotificationTransport::class, fn () => new class extends App\Services\NotificationTransport {
    public function __construct() {}
    public function send(App\Models\NotificationDelivery $delivery): App\Services\NotificationResult {
        Illuminate\Support\Facades\DB::table('notification_test_receipts')->insert(['id' => Illuminate\Support\Str::uuid(), 'transaction_level' => Illuminate\Support\Facades\DB::transactionLevel()]);
        usleep(250000);
        return new App\Services\NotificationResult(true);
    }
});
echo app(App\Services\NotificationDispatcher::class)->dispatch($argv[1]);
PHP;
        file_put_contents($script, $code);
        $processes = [new Process([PHP_BINARY, $script, $delivery->id]), new Process([PHP_BINARY, $script, $delivery->id])];
        try {
            foreach ($processes as $process) {
                $process->setWorkingDirectory(base_path());
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $this->assertSame('sent', $delivery->fresh()->status);
            $this->assertSame(1, $delivery->fresh()->attempts);
            $this->assertSame(1, DB::table('notification_test_receipts')->count());
            $this->assertSame(0, DB::table('notification_test_receipts')->value('transaction_level'));
            $results = array_map(fn ($p) => $p->getOutput(), $processes);
            sort($results);
            $this->assertSame(['sent', 'skipped'], $results);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            unlink($script);
            Schema::dropIfExists('notification_test_receipts');
        }
    }
}
