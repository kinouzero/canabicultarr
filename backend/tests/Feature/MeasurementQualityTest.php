<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\MeasurementQuality;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MeasurementQualityTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        Measurement::query()->delete();
        parent::tearDown();
    }

    private function sensor(): Sensor
    {
        $asset = Asset::create(['name' => 'Quality device']);
        $type = MeasurementType::create(['name' => 'Quality moisture', 'code' => 'quality_moisture', 'unit' => '%', 'nature' => 'substrate_moisture']);

        return Sensor::forceCreate(['name' => 'Quality channel', 'channel_key' => 'quality', 'asset_id' => $asset->id, 'measurement_type_id' => $type->id, 'is_enabled' => true, 'expected_interval_seconds' => 60]);
    }

    private function reading(Sensor $sensor, string $quality, string $value, int $seconds): Measurement
    {
        return Measurement::create(['sensor_id' => $sensor->id, 'value' => $value, 'unit' => '%', 'observed_at' => now()->subSeconds($seconds), 'received_at' => now(), 'quality' => $quality]);
    }

    public function test_history_exposes_every_quality_while_latest_and_charts_use_the_same_usable_set(): void
    {
        $this->travelTo(now()->startOfSecond());
        $sensor = $this->sensor();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->reading($sensor, 'unknown', '20', 4);
        $good = $this->reading($sensor, 'good', '30', 3);
        $this->reading($sensor, 'suspect', '90', 2);
        $this->reading($sensor, 'invalid', '100', 1);
        $this->actingAs($admin)->getJson('/api/v1/sensors/'.$sensor->id.'/measurements')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.quality', 'invalid')->assertJsonPath('summary.latest.id', $good->id)->assertJsonPath('summary.latest.quality', 'good');
        $this->getJson('/api/v1/reports/sensors/'.$sensor->id)->assertOk()->assertJsonPath('data.statistics.count', 2)->assertJsonPath('data.statistics.mean', '25.000000000');
    }

    public function test_invalid_quality_does_not_reset_missing_data_alerts_or_trigger_thresholds(): void
    {
        $this->travelTo(now()->startOfSecond());
        $sensor = $this->sensor();
        $this->reading($sensor, 'invalid', '99', 1);
        $rule = AlertRule::create(['sensor_id' => $sensor->id, 'name' => 'Missing valid samples', 'kind' => 'missing', 'severity' => 'warning', 'delay_seconds' => 60, 'activated_at' => now()->subMinutes(2)]);
        $this->assertSame('alerting', app(AlertEvaluator::class)->evaluate($rule)->last_result);
        $this->assertSame('missing_reading', $rule->episodes()->sole()->reason);
        $threshold = AlertRule::create(['sensor_id' => $sensor->id, 'name' => 'Threshold', 'kind' => 'threshold', 'severity' => 'warning', 'minimum' => 10, 'maximum' => 20, 'max_age_seconds' => 300, 'activated_at' => now()->subMinutes(2)]);
        $this->assertSame('waiting', app(AlertEvaluator::class)->evaluate($threshold)->last_result);
        $this->reading($sensor, 'unknown', '15', 0);
        $this->assertSame('normal', app(AlertEvaluator::class)->evaluate($rule)->last_result);
    }

    public function test_old_rows_remain_unknown_and_migration_round_trip_preserves_numeric_history(): void
    {
        $sensor = $this->sensor();
        $sample = $this->reading($sensor, 'unknown', '12.123456789', 1);
        $before = $sample->fresh()->getRawOriginal();
        unset($before['quality']);
        $migration = require database_path('migrations/2026_11_15_160000_add_measurement_quality.php');
        $migration->down();
        $migration->up();
        $after = Measurement::sole()->getRawOriginal();
        $this->assertSame('unknown', $after['quality']);
        unset($after['quality']);
        $this->assertSame($before, $after);
    }

    public function test_percentage_range_anomalies_are_suspect_without_rejecting_historical_numeric_evidence(): void
    {
        $type = $this->sensor()->measurementType;
        $this->assertSame('suspect', MeasurementQuality::normalize('good', '100.000000001', $type));
        $this->assertSame('suspect', MeasurementQuality::normalize(null, '-0.000000001', $type));
        $this->assertSame('unknown', MeasurementQuality::normalize(null, '35.000000000', $type));
        $this->assertSame('invalid', MeasurementQuality::normalize('invalid', '20.000000000', $type));
    }

    public function test_database_rejects_unrecognized_quality_without_changing_a_sample(): void
    {
        $sample = $this->reading($this->sensor(), 'unknown', '25', 1);
        try {
            DB::table('measurements')->where('id', $sample->id)->update(['quality' => 'unsupported']);
            $this->fail('Unsupported quality must fail at the database boundary.');
        } catch (QueryException) {
            $this->assertSame('unknown', $sample->fresh()->quality);
        }
    }

    public function test_future_samples_do_not_report_usable_current_data_in_overviews(): void
    {
        $this->travelTo(now()->startOfSecond());
        $sensor = $this->sensor();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->reading($sensor, 'good', '25', -60);
        $this->actingAs($admin)->getJson('/api/v1/measurements/overview')->assertOk()->assertJsonPath('summary.missing', 1);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.sensors.withReadings', 0);
    }
}
