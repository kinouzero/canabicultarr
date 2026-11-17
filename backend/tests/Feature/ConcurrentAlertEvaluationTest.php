<?php

namespace Tests\Feature;

use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\MeasurementType;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\AssetAdministration;
use App\Services\MonitoringAlerts;
use App\Services\SensorAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentAlertEvaluationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_overlapping_evaluators_create_one_missing_report_episode(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent row locking requires PostgreSQL.');
        }
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Test Device']);
        $type = MeasurementType::create(['name' => 'Type', 'code' => 'test']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => 'Probe', 'device_id' => $asset->id, 'measurement_type_id' => $type->id, 'channel_key' => 'probe']);
        $rule = app(MonitoringAlerts::class)->save($admin, ['name' => 'Missing', 'sensor_id' => $sensor->id, 'kind' => 'missing', 'delay_seconds' => 120, 'severity' => 'warning']);
        $rule->activated_at = now()->subHour();
        $rule->save();
        $id = $rule->id;
        $database = DB::connection()->getDatabaseName();
        $tasks = [];
        for ($i = 0; $i < 2; $i++) {
            $tasks[] = static function () use ($id, $database) {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database) {
                    return 'wrong_test_environment';
                }

                return app(AlertEvaluator::class)->evaluate(AlertRule::findOrFail($id))->last_result;
            };
        }
        $this->assertSame(['alerting', 'alerting'], Concurrency::driver('process')->run($tasks, timeout: 20));
        $this->assertDatabaseCount('alert_episodes', 1);
        $this->assertNull(AlertEpisode::sole()->resolved_at);
    }
}
