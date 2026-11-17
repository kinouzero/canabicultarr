<?php

namespace Tests\Feature;

use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\HomeAssistantLink;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\SensorAdministration;
use App\Services\SensorReporting;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class SensorReportingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00Z'));
    }

    private function graph(string $name = 'Test Probe'): array
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Test Device']);
        $type = MeasurementType::create(['name' => 'Test Type', 'code' => 'type_'.Str::random(8), 'unit' => 'test']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => $name, 'device_id' => $asset->id, 'measurement_type_id' => $type->id, 'channel_key' => 'probe', 'expected_interval_seconds' => 60]);
        $link = HomeAssistantLink::create(['sensor_id' => $sensor->id, 'instance_key' => 'test', 'entity_id' => 'sensor.test_'.Str::random(8)]);

        return [$admin, $sensor, $link];
    }

    private function sample(Sensor $sensor, HomeAssistantLink $link, string $value, string $observed = '2026-11-06T11:00:00Z', array $extra = []): Measurement
    {
        return Measurement::create(['sensor_id' => $sensor->id, 'home_assistant_link_id' => $link->id, 'value' => $value, 'unit' => 'test', 'observed_at' => $observed, 'received_at' => '2026-11-06T11:30:00Z', ...$extra]);
    }

    private function criteria(array $extra = []): array
    {
        return ['date_from' => '2026-11-06T10:00:00Z', 'date_to' => '2026-11-06T12:00:00Z', ...$extra];
    }

    private function url(Sensor $sensor, string $suffix = '', array $extra = []): string
    {
        return '/api/v1/reports/sensors/'.$sensor->id.$suffix.'?'.http_build_query($this->criteria($extra));
    }

    public function test_guests_cannot_read_reports_or_exports(): void
    {
        [$admin,$sensor] = $this->graph();
        $this->getJson($this->url($sensor))->assertUnauthorized();
        $this->getJson($this->url($sensor, '/export'))->assertUnauthorized();
    }

    public function test_active_members_can_report_and_export_but_inactive_sessions_cannot(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->sample($sensor, $link, '1');
        $member = User::factory()->create();
        $this->actingAs($member)->getJson($this->url($sensor))->assertOk()->assertJsonPath('data.statistics.count', 1);
        $this->get($this->url($sensor, '/export'))->assertOk();
        $member->forceFill(['is_active' => false])->save();
        $this->actingAs($member)->getJson($this->url($sensor))->assertUnauthorized();
    }

    public function test_exact_statistics_and_utc_buckets_include_empty_intervals(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->sample($sensor, $link, '0.1', '2026-11-06T10:00:00Z');
        $this->sample($sensor, $link, '0.2', '2026-11-06T10:30:00Z');
        $this->sample($sensor, $link, '0.3', '2026-11-06T11:00:00Z');
        $this->actingAs($admin)->getJson($this->url($sensor))->assertOk()->assertJsonPath('data.statistics.count', 3)->assertJsonPath('data.statistics.minimum', '0.100000000')->assertJsonPath('data.statistics.maximum', '0.300000000')->assertJsonPath('data.statistics.mean', '0.200000000')->assertJsonCount(3, 'data.buckets')->assertJsonPath('data.buckets.0.count', 2)->assertJsonPath('data.buckets.0.mean', '0.150000000')->assertJsonPath('data.buckets.2.count', 0)->assertJsonPath('data.buckets.2.mean', null)->assertJsonMissingPath('data.sensor.device.serialNumber');
        Http::assertNothingSent();
    }

    public function test_average_rounds_half_away_from_zero_at_nine_decimal_places(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->sample($sensor, $link, '-0.000000001', '2026-11-06T10:00:00Z');
        $this->sample($sensor, $link, '0', '2026-11-06T10:01:00Z');
        $result = app(SensorReporting::class)->report($admin, $sensor, $this->criteria());
        $this->assertSame('-0.000000001', $result['statistics']['mean']);
    }

    public function test_empty_reports_have_null_statistics_and_no_invented_coverage(): void
    {
        [$admin,$sensor] = $this->graph();
        $result = app(SensorReporting::class)->report($admin, $sensor, $this->criteria());
        $this->assertSame(0, $result['statistics']['count']);
        $this->assertNull($result['statistics']['minimum']);
        $this->assertNull($result['statistics']['maximum']);
        $this->assertNull($result['statistics']['mean']);
        $this->assertSame(7200, $result['statistics']['largestGapSeconds']);
        $this->assertSame(1, $result['statistics']['gapCount']);
        $this->assertNull($result['statistics']['first']);
    }

    public function test_exact_time_bounds_offsets_and_sensor_scope_are_respected(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->sample($sensor, $link, '1', '2026-11-06T11:00:00.123456Z');
        $this->sample($sensor, $link, '9', '2026-11-06T11:00:00.123457Z');
        [$other,$otherSensor,$otherLink] = $this->graph('Other');
        $this->sample($otherSensor, $otherLink, '99');
        $point = '2026-11-06T12:00:00.123456+01:00';
        $this->actingAs($admin)->getJson($this->url($sensor, '', ['date_from' => $point, 'date_to' => $point]))->assertOk()->assertJsonPath('data.statistics.count', 1)->assertJsonPath('data.statistics.mean', '1.000000000');
    }

    public function test_invalid_inverted_future_and_excessive_ranges_are_rejected(): void
    {
        [$admin,$sensor] = $this->graph();
        $this->actingAs($admin);
        foreach ([['date_from' => 'yesterday'], ['date_from' => '2026-11-07T00:00:00Z'], ['date_to' => '2026-11-06T12:00:01Z'], ['date_from' => '2026-01-01T00:00:00Z'], ['group_by' => 'minute'], ['as_of' => '2026-11-07T00:00:00Z']] as $criteria) {
            $this->getJson($this->url($sensor, '', $criteria))->assertUnprocessable();
        }
    }

    public function test_31_day_limit_and_daily_buckets_are_supported(): void
    {
        [$admin,$sensor] = $this->graph();
        $result = app(SensorReporting::class)->report($admin, $sensor, ['date_from' => '2026-10-06T12:00:00Z', 'date_to' => '2026-11-06T12:00:00Z', 'group_by' => 'day']);
        $this->assertCount(32, $result['buckets']);
        $this->assertSame('2026-10-06T12:00:00.000000Z', $result['buckets'][0]['startAt']);
        $this->assertSame('2026-11-06T12:00:00.000000Z', $result['buckets'][31]['endAt']);
    }

    public function test_as_of_excludes_late_imports_from_report_and_export(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->sample($sensor, $link, '1', '2026-11-06T10:00:00Z');
        $this->sample($sensor, $link, '2', '2026-11-06T11:00:00Z', ['received_at' => '2026-11-06T12:00:01Z']);
        $report = app(SensorReporting::class)->report($admin, $sensor, $this->criteria());
        $this->assertSame(1, $report['statistics']['count']);
        $response = $this->actingAs($admin)->get($this->url($sensor, '/export', ['as_of' => $report['window']['asOf']]));
        $response->assertOk();
        $this->assertStringNotContainsString('2.000000000', $response->streamedContent());
    }

    public function test_mixed_units_are_never_silently_aggregated_or_exported(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->sample($sensor, $link, '1', extra: ['unit' => 'different']);
        $this->actingAs($admin)->getJson($this->url($sensor))->assertConflict()->assertJsonPath('error.code', 'reports.errors.unitMismatch');
        $this->getJson($this->url($sensor, '/export'))->assertConflict();
    }

    public function test_gaps_include_window_edges_and_omit_inferred_count_without_interval(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->sample($sensor, $link, '1', '2026-11-06T10:00:00Z');
        $this->sample($sensor, $link, '2', '2026-11-06T10:01:00Z');
        $result = app(SensorReporting::class)->report($admin, $sensor, $this->criteria());
        $this->assertSame(7140, $result['statistics']['largestGapSeconds']);
        $this->assertSame(1, $result['statistics']['gapCount']);
        $sensor->expected_interval_seconds = null;
        $sensor->save();
        $result = app(SensorReporting::class)->report($admin, $sensor, $this->criteria());
        $this->assertNull($result['statistics']['gapCount']);
        $this->assertNull($result['statistics']['gapThresholdSeconds']);
    }

    public function test_alert_summary_uses_event_times_and_interval_overlap_not_current_status(): void
    {
        [$admin,$sensor] = $this->graph();
        $rule = AlertRule::create(['sensor_id' => $sensor->id, 'name' => 'Rule', 'kind' => 'missing', 'severity' => 'warning', 'delay_seconds' => 120, 'activated_at' => '2026-11-06T00:00:00Z']);
        AlertEpisode::create(['alert_rule_id' => $rule->id, 'rule_snapshot' => ['name' => 'Snapshot'], 'severity' => 'warning', 'reason' => 'overdue_reading', 'opened_at' => '2026-11-06T09:00:00Z', 'last_detected_at' => '2026-11-06T10:30:00Z', 'resolved_at' => '2026-11-06T11:00:00Z', 'acknowledged_at' => '2026-11-06T10:00:00Z']);
        AlertEpisode::create(['alert_rule_id' => $rule->id, 'rule_snapshot' => ['name' => 'Snapshot'], 'severity' => 'warning', 'reason' => 'overdue_reading', 'opened_at' => '2026-11-06T11:30:00Z', 'last_detected_at' => '2026-11-06T11:30:00Z']);
        $result = app(SensorReporting::class)->report($admin, $sensor, $this->criteria());
        $this->assertSame(['overlapping' => 2, 'opened' => 1, 'resolved' => 1, 'acknowledged' => 1, 'activeAtEnd' => 1], $result['alerts']);
    }

    public function test_csv_is_a_private_attachment_with_exact_values_utf8_and_safe_text_cells(): void
    {
        [$admin,$sensor,$link] = $this->graph('=HYPERLINK("x")');
        $sensor->device->update(['name' => '＋SUM(1,2)']);
        $this->sample($sensor, $link, '-1.123456789', extra: ['location_snapshot' => ['site' => ['name' => '	=cmd'], 'room' => ['name' => 'Plain, "quoted"'], 'zone' => ['name' => '@cmd']]]);
        $response = $this->actingAs($admin)->get($this->url($sensor, '/export'));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        $row = fgetcsv($stream, escape: '');
        fclose($stream);
        $this->assertSame('measurement_id', $header[0]);
        $this->assertSame("'=HYPERLINK(\"x\")", $row[2]);
        $this->assertSame("'＋SUM(1,2)", $row[3]);
        $this->assertSame('-1.123456789', $row[6]);
        $this->assertSame("' =cmd", $row[8]);
        $this->assertSame('Plain, "quoted"', $row[9]);
        $this->assertSame("'@cmd", $row[10]);
        $this->assertSame(strlen($csv), (int) $response->headers->get('content-length'));
    }

    private function seedMany(Sensor $sensor, HomeAssistantLink $link, int $count): void
    {
        $start = CarbonImmutable::parse('2026-11-06T10:00:00Z');
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'sensor_id' => $sensor->id, 'home_assistant_link_id' => $link->id, 'value' => '1.000000000', 'unit' => 'test', 'observed_at' => '2026-11-06 10:00:00.'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'received_at' => '2026-11-06 11:30:00.000000'];
            if (count($rows) === 500) {
                DB::table('measurements')->insert($rows);
                $rows = [];
            }
        }
        if ($rows) {
            DB::table('measurements')->insert($rows);
        }
    }

    public function test_csv_row_limit_rejects_excess_instead_of_truncating(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->seedMany($sensor, $link, 10001);
        $this->actingAs($admin)->getJson($this->url($sensor, '/export'))->assertUnprocessable()->assertJsonPath('error.code', 'reports.errors.exportTooLarge');
        $this->assertDatabaseCount('measurements', 10001);
    }

    public function test_large_reports_aggregate_in_postgres_and_keep_the_portable_read_limit(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->seedMany($sensor, $link, 100001);
        $response = $this->actingAs($admin)->getJson($this->url($sensor));
        if (DB::getDriverName() === 'pgsql') {
            $response->assertOk()->assertJsonPath('data.statistics.count', 100001)->assertJsonPath('data.statistics.mean', '1.000000000');
        } else {
            $response->assertUnprocessable()->assertJsonPath('error.code', 'reports.errors.tooManyReadings');
        }
    }

    public function test_chunked_reads_preserve_all_rows_across_page_boundaries(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->seedMany($sensor, $link, 1001);
        $result = app(SensorReporting::class)->report($admin, $sensor, $this->criteria());
        $this->assertSame(1001, $result['statistics']['count']);
        $this->assertSame('1.000000000', $result['statistics']['mean']);
    }

    public function test_authorization_is_rechecked_after_generation_before_export_headers(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->sample($sensor, $link, '1');
        $once = false;
        Event::listen(TransactionCommitted::class, function () use ($admin, &$once) {
            if (! $once) {
                $once = true;
                User::whereKey($admin->id)->update(['session_version' => 1]);
            }
        });
        $this->actingAs($admin)->getJson($this->url($sensor, '/export'))->assertUnauthorized()->assertJsonPath('error.code', 'auth.errors.unauthenticated');
    }

    public function test_export_logs_identifiers_and_count_without_names_values_or_filters(): void
    {
        [$admin,$sensor,$link] = $this->graph();
        $this->sample($sensor, $link, '123.5');
        Log::spy();
        $data = app(SensorReporting::class)->export($admin, $sensor, $this->criteria());
        fclose($data['stream']);
        Log::shouldHaveReceived('notice')->with('report.csv_exported', ['actor_uuid' => $admin->uuid, 'sensor_uuid' => $sensor->id, 'rows' => 1])->once();
    }

    public function test_export_rate_limit_is_separate_from_report_reads(): void
    {
        [$admin,$sensor] = $this->graph();
        $this->actingAs($admin);
        for ($i = 0; $i < 10; $i++) {
            $this->get($this->url($sensor, '/export'))->assertOk();
        }$this->getJson($this->url($sensor, '/export'))->assertTooManyRequests();
        $this->getJson($this->url($sensor))->assertOk();
    }

    public function test_postgresql_snapshot_does_not_mix_concurrent_backfills(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Repeatable-read snapshot verification requires PostgreSQL.');
        }
        [$admin,$sensor,$link] = $this->graph();
        $this->sample($sensor, $link, '1', '2026-11-06T10:00:00Z');
        config(['database.connections.report_writer' => config('database.connections.'.config('database.default'))]);
        $writer = DB::connection('report_writer');
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $once = false;
        DB::listen(function ($event) use ($writer, $sensor, $link, &$once) {
            if (! $once && $event->connectionName !== 'report_writer' && str_contains(strtolower($event->sql), 'count(*)') && str_contains($event->sql, 'measurements')) {
                $once = true;
                $writer->table('measurements')->insert(['id' => (string) Str::uuid(), 'sensor_id' => $sensor->id, 'home_assistant_link_id' => $link->id, 'value' => '9.000000000', 'unit' => 'test', 'observed_at' => '2026-11-06 11:00:00.000000', 'received_at' => '2026-11-06 11:30:00.000000']);
            }
        });
        $first = app(SensorReporting::class)->report($admin, $sensor, $this->criteria());
        $this->assertSame(1, $first['statistics']['count']);
        $next = app(SensorReporting::class)->report($admin, $sensor, $this->criteria());
        $this->assertSame(2, $next['statistics']['count']);
        DB::disconnect('report_writer');
    }
}
