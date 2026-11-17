<?php

namespace Tests\Feature;

use App\Exceptions\ReportException;
use App\Jobs\GenerateMeasurementReport;
use App\Models\Asset;
use App\Models\ConnectionSetting;
use App\Models\MeasurementReportExport;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\User;
use App\Services\ConnectionConfiguration;
use App\Services\MeasurementReportExports;
use App\Services\MeasurementReportPdf;
use App\Services\SensorReporting;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MeasurementReportExportTest extends TestCase
{
    use DatabaseMigrations;

    private User $reader;

    private Sensor $sensor;

    private QueueManager $queueManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['queue.default' => 'database', 'documents.disk' => 'documents']);
        Storage::fake('documents');
        $this->queueManager = Queue::getFacadeRoot();
        Queue::fake();
        $this->reader = User::factory()->create();
        $asset = Asset::create(['name' => 'Export device']);
        $type = MeasurementType::create(['name' => 'Substrate moisture', 'code' => 'export_moisture', 'unit' => '%', 'nature' => 'substrate_moisture']);
        $this->sensor = Sensor::forceCreate(['name' => 'Export channel', 'channel_key' => 'export', 'asset_id' => $asset->id, 'measurement_type_id' => $type->id, 'is_enabled' => true, 'expected_interval_seconds' => 60]);
    }

    protected function tearDown(): void
    {
        DB::table('measurement_report_exports')->delete();
        DB::table('measurements')->delete();
        parent::tearDown();
    }

    private function input(string $format = 'csv'): array
    {
        return ['format' => $format, 'locale' => 'fr', 'timezone' => 'Europe/Paris', 'request_key' => (string) Str::uuid(), 'date_from' => now()->subHours(4)->toISOString(), 'date_to' => now()->toISOString(), 'as_of' => now()->toISOString(), 'group_by' => 'hour'];
    }

    public function test_workers_reload_changed_storage_adapters_but_keep_unchanged_disks(): void
    {
        config(['connection_settings.storage.AWS_BUCKET.locked' => false]);
        $configuration = app(ConnectionConfiguration::class);
        $local = Storage::disk('documents');
        $old = Storage::fake('documents_s3');
        $row = ConnectionSetting::findOrFail('storage');
        $row->values = ['AWS_BUCKET' => 'updated-private-bucket'];
        $row->save();

        $configuration->apply();
        $current = Storage::disk('documents_s3');
        $this->assertNotSame($old, $current);
        $this->assertSame('updated-private-bucket', $current->getConfig()['bucket']);
        $this->assertSame($local, Storage::disk('documents'));
        $configuration->apply();
        $this->assertSame($current, Storage::disk('documents_s3'));
    }

    private function create(string $format = 'csv'): MeasurementReportExport
    {
        $id = $this->actingAs($this->reader)->postJson('/api/v1/reports/sensors/'.$this->sensor->id.'/exports', $this->input($format))->assertAccepted()->json('data.id');

        return MeasurementReportExport::findOrFail($id);
    }

    private function readings(int $count): void
    {
        foreach (array_chunk(range(1, $count), 500) as $batch) {
            DB::table('measurements')->insert(array_map(fn ($i) => ['id' => (string) Str::uuid(), 'sensor_id' => $this->sensor->id, 'value' => '42.5', 'unit' => '%', 'observed_at' => now()->subSeconds($i)->format('Y-m-d H:i:s.u'), 'received_at' => now()->format('Y-m-d H:i:s.u')], $batch));
        }
    }

    public function test_requests_are_idempotent_and_private_until_ready(): void
    {
        $input = $this->input();
        $root = '/api/v1/reports/sensors/'.$this->sensor->id.'/exports';
        $this->postJson($root, $input)->assertUnauthorized();
        $id = $this->actingAs($this->reader)->postJson($root, $input)->assertAccepted()->assertJsonPath('data.status', 'queued')->json('data.id');
        $input['request_key'] = strtoupper($input['request_key']);
        $this->postJson($root, $input)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->postJson($root, [...$input, 'format' => 'pdf'])->assertConflict();
        Queue::assertPushed(GenerateMeasurementReport::class, 1);
        $this->getJson('/api/v1/report-exports/'.$id.'/download')->assertConflict();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/report-exports/'.$id.'/download')->assertForbidden();
        $this->postJson('/api/v1/report-exports/'.$id.'/cancel')->assertForbidden();
        Storage::disk('documents')->assertDirectoryEmpty('measurement-exports');
    }

    public function test_background_csv_is_complete_above_the_old_limit_and_replay_has_one_file(): void
    {
        $this->readings(10001);
        $export = $this->create();
        $service = app(MeasurementReportExports::class);
        $service->generate($export->id);
        $this->assertSame('ready', $export->fresh()->status);
        $this->assertSame(10001, $export->fresh()->processed_rows);
        $this->assertSame(100, $export->fresh()->progress);
        $stream = Storage::disk('documents')->readStream($service->path($export));
        $lines = 0;
        while (fgetcsv($stream, escape: '') !== false) {
            $lines++;
        }
        fclose($stream);
        $this->assertSame(10002, $lines);
        $before = Storage::disk('documents')->get($service->path($export));
        $service->generate($export->id);
        $this->assertSame($before, Storage::disk('documents')->get($service->path($export)));
        $this->assertCount(1, Storage::disk('documents')->allFiles('measurement-exports'));
        $this->get('/api/v1/report-exports/'.$export->id.'/download')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_pdf_handles_empty_periods_and_escapes_user_html_without_remote_assets(): void
    {
        $this->sensor->update(['name' => '<img src="https://private.example.test/pixel">']);
        $export = $this->create('pdf');
        $export->update(['display' => [...$export->display, 'dateFormat' => 'localized-long']]);
        $this->assertSame('16 novembre 2026 00:30', app(MeasurementReportPdf::class)->date('2026-11-15T23:30:00Z', $export));
        $this->assertSame('42,5', app(MeasurementReportPdf::class)->number('42.500000000', 'fr'));
        $this->assertSame('999,999,999,999,999.123456789', app(MeasurementReportPdf::class)->number('999999999999999.123456789', 'en'));
        $report = app(SensorReporting::class)->report($this->reader, $this->sensor, $export->criteria);
        $html = app(MeasurementReportPdf::class)->html($report, $export);
        $this->assertStringContainsString('Aucune mesure exploitable', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img src="https://private.example.test', $html);
        app(MeasurementReportExports::class)->generate($export->id);
        $this->assertSame('ready', $export->fresh()->status);
        $bytes = Storage::disk('documents')->get(app(MeasurementReportExports::class)->path($export));
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);
        $this->assertStringNotContainsString('/JavaScript', $bytes);
        $this->get('/api/v1/report-exports/'.$export->id.'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pdf_keeps_unrelated_percentage_natures_out_of_the_report(): void
    {
        $this->readings(2);
        $batteryType = MeasurementType::create(['name' => 'PRIVATE BATTERY', 'code' => 'export_battery', 'unit' => '%', 'nature' => 'battery']);
        $battery = Sensor::forceCreate(['name' => 'PRIVATE BATTERY', 'channel_key' => 'battery', 'asset_id' => $this->sensor->asset_id, 'measurement_type_id' => $batteryType->id]);
        DB::table('measurements')->insert(['id' => (string) Str::uuid(), 'sensor_id' => $battery->id, 'value' => '99', 'unit' => '%', 'observed_at' => now()->subSeconds(3)->format('Y-m-d H:i:s.u'), 'received_at' => now()->format('Y-m-d H:i:s.u')]);
        $export = $this->create('pdf');
        $report = app(SensorReporting::class)->report($this->reader, $this->sensor, $export->criteria);
        $this->assertSame(2, $report['statistics']['count']);
        $this->assertSame('42.500000000', $report['statistics']['mean']);
        $html = app(MeasurementReportPdf::class)->html($report, $export);
        $this->assertStringNotContainsString('PRIVATE BATTERY', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        app(MeasurementReportExports::class)->generate($export->id);
        $this->assertSame('ready', $export->fresh()->status);
    }

    public function test_cancellation_expiration_and_scope_loss_prevent_download_and_cleanup_files(): void
    {
        $export = $this->create();
        $this->postJson('/api/v1/report-exports/'.$export->id.'/cancel')->assertNoContent();
        app(MeasurementReportExports::class)->generate($export->id);
        $this->assertSame('cancelled', $export->fresh()->status);
        Storage::disk('documents')->assertMissing(app(MeasurementReportExports::class)->path($export));
        $export = $this->create();
        app(MeasurementReportExports::class)->generate($export->id);
        $this->travel(24)->hours();
        $this->getJson('/api/v1/report-exports/'.$export->id.'/download')->assertGone();
        app(MeasurementReportExports::class)->cleanup();
        $this->assertSame('expired', $export->fresh()->status);
        Storage::disk('documents')->assertMissing(app(MeasurementReportExports::class)->path($export));
        $export = $this->create();
        app(MeasurementReportExports::class)->generate($export->id);
        $this->reader->forceFill(['location_access' => [['type' => 'site', 'id' => Site::create(['name' => 'Other'])->id]]])->save();
        $this->getJson('/api/v1/report-exports/'.$export->id.'/download')->assertForbidden();
    }

    public function test_pending_limits_and_invalid_options_do_not_create_unbounded_work(): void
    {
        $root = '/api/v1/reports/sensors/'.$this->sensor->id.'/exports';
        $this->actingAs($this->reader)->postJson($root, [...$this->input(), 'timezone' => '../../etc/passwd'])->assertUnprocessable();
        $this->create();
        $this->create('pdf');
        $this->postJson($root, $this->input())->assertStatus(429);
        $this->assertDatabaseCount('measurement_report_exports', 2);
        Queue::assertPushed(GenerateMeasurementReport::class, 2);
    }

    public function test_queued_work_rechecks_scope_and_failed_generation_has_no_partial_file(): void
    {
        $export = $this->create();
        $this->reader->forceFill(['location_access' => [['type' => 'site', 'id' => Site::create(['name' => 'Other'])->id]]])->save();
        app(MeasurementReportExports::class)->generate($export->id);
        $this->assertSame('cancelled', $export->fresh()->status);
        Storage::disk('documents')->assertMissing(app(MeasurementReportExports::class)->path($export));
        $this->reader->forceFill(['location_access' => null])->save();
        $export = $this->create('pdf');
        $this->mock(MeasurementReportPdf::class)->shouldReceive('render')->once()->andThrow(new ReportException('reportExports.errors.tooLarge'));
        app(MeasurementReportExports::class)->generate($export->id);
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame('reportExports.errors.tooLarge', $export->fresh()->error_code);
        Storage::disk('documents')->assertMissing(app(MeasurementReportExports::class)->path($export));
        $export = $this->create('pdf');
        $this->mock(SensorReporting::class)->shouldReceive('report')->once()->andReturn(['statistics' => ['count' => 1000001]]);
        app(MeasurementReportExports::class)->generate($export->id);
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame('reportExports.errors.tooLarge', $export->fresh()->error_code);
    }

    public function test_cancellation_racing_job_claim_is_not_reactivated(): void
    {
        $export = $this->create();
        Gate::before(function ($user, $ability) use ($export) {
            if ($ability === 'view') {
                MeasurementReportExport::whereKey($export->id)->update(['status' => 'cancelled']);
            }

            return null;
        });
        app(MeasurementReportExports::class)->generate($export->id);
        $this->assertSame('cancelled', $export->fresh()->status);
        Storage::disk('documents')->assertMissing(app(MeasurementReportExports::class)->path($export));
    }

    public function test_storage_read_failure_does_not_expose_provider_details(): void
    {
        $export = $this->create();
        app(MeasurementReportExports::class)->generate($export->id);
        $disk = Mockery::mock(Storage::disk('documents'));
        $disk->shouldReceive('readStream')->once()->andThrow(new \RuntimeException('fictional-private-provider-detail'));
        Storage::shouldReceive('disk')->with('documents')->andReturn($disk);
        $response = $this->getJson('/api/v1/report-exports/'.$export->id.'/download')->assertStatus(503)->assertJsonPath('error.code', 'reportExports.errors.failed');
        $this->assertStringNotContainsString('fictional-private-provider-detail', $response->getContent());
    }

    public function test_removed_storage_configuration_fails_closed_without_creating_a_provider_client(): void
    {
        $export = $this->create();
        app(MeasurementReportExports::class)->generate($export->id);
        $export->update(['disk' => 'documents_s3']);
        config(['filesystems.disks.documents_s3.key' => null, 'filesystems.disks.documents_s3.secret' => null, 'filesystems.disks.documents_s3.bucket' => null]);
        Storage::shouldReceive('disk')->never();
        $this->getJson('/api/v1/report-exports/'.$export->id.'/download')->assertStatus(503)->assertJsonPath('error.code', 'reportExports.errors.failed');
    }

    public function test_access_revoked_while_opening_a_download_closes_the_private_stream(): void
    {
        $export = $this->create();
        $service = app(MeasurementReportExports::class);
        $service->generate($export->id);
        $disk = Storage::disk('documents');
        $opened = null;
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('readStream')->once()->andReturnUsing(function ($path) use ($disk, &$opened) {
            $opened = $disk->readStream($path);
            $this->reader->forceFill(['is_active' => false])->save();

            return $opened;
        });
        Storage::shouldReceive('disk')->with('documents')->andReturn($proxy);
        $this->getJson('/api/v1/report-exports/'.$export->id.'/download')->assertUnauthorized();
        $this->assertNotNull($opened);
        $this->assertFalse(is_resource($opened));
    }

    public function test_failed_storage_write_never_publishes_a_downloadable_export(): void
    {
        $export = $this->create();
        $disk = Storage::disk('documents');
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('documents')->andReturn($proxy);
        try {
            app(MeasurementReportExports::class)->generate($export->id);
            $this->fail('A transient storage failure must remain retryable.');
        } catch (ReportException $error) {
            $this->assertSame(503, $error->status);
            $this->assertSame('reportExports.errors.failed', $error->errorCode);
        }
        $this->assertSame('processing', $export->fresh()->status);
        $this->getJson('/api/v1/report-exports/'.$export->id.'/download')->assertConflict();
        (new GenerateMeasurementReport($export->id))->failed(null);
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame('reportExports.errors.failed', $export->fresh()->error_code);
        $this->getJson('/api/v1/report-exports/'.$export->id.'/download')->assertConflict();
        $disk->assertDirectoryEmpty('measurement-exports');
    }

    public function test_unconfigured_export_destination_rejects_admission_without_queueing_work(): void
    {
        config(['documents.disk' => 'documents_s3', 'filesystems.disks.documents_s3.key' => null]);
        $this->actingAs($this->reader)->postJson('/api/v1/reports/sensors/'.$this->sensor->id.'/exports', $this->input())
            ->assertStatus(503)->assertJsonPath('error.code', 'reportExports.errors.failed');
        $this->assertDatabaseCount('measurement_report_exports', 0);
        Queue::assertNothingPushed();
    }

    public function test_configured_s3_storage_stays_private_and_downloads_use_the_server_proxy(): void
    {
        config(['documents.disk' => 'documents_s3', 'filesystems.disks.documents_s3.key' => 'fictional-key', 'filesystems.disks.documents_s3.secret' => 'fictional-secret', 'filesystems.disks.documents_s3.bucket' => 'fictional-bucket']);
        Storage::fake('documents_s3');
        $export = $this->create();
        app(MeasurementReportExports::class)->generate($export->id);
        $this->assertSame('ready', $export->fresh()->status);
        $this->assertSame('documents_s3', $export->disk);
        $this->assertSame('private', Storage::disk('documents_s3')->getVisibility(app(MeasurementReportExports::class)->path($export)));
        $this->get('/api/v1/report-exports/'.$export->id.'/download')->assertOk()->assertHeaderMissing('Location');
        $response = $this->getJson('/api/v1/reports/sensors/'.$this->sensor->id.'/exports')->assertOk();
        $this->assertStringNotContainsString('fictional-secret', $response->getContent());
    }

    public function test_ready_file_availability_reflects_the_current_permission_fingerprint(): void
    {
        $export = $this->create();
        app(MeasurementReportExports::class)->generate($export->id);
        $root = '/api/v1/reports/sensors/'.$this->sensor->id.'/exports';
        $this->getJson($root)->assertOk()->assertJsonPath('data.0.canDownload', true);
        $this->reader->forceFill(['permission_grants' => ['inventory.manage']])->save();
        $this->getJson($root)->assertOk()->assertJsonPath('data.0.status', 'ready')->assertJsonPath('data.0.canDownload', false);
        $this->getJson('/api/v1/report-exports/'.$export->id.'/download')->assertForbidden();
    }

    public function test_real_queue_generates_one_private_file_and_completion_replay_is_a_noop(): void
    {
        if (getenv('REDIS_TESTS') !== '1') {
            $this->markTestSkipped('Requires disposable Redis DBs14/15.');
        }
        config(['queue.default' => 'redis', 'cache.default' => 'redis', 'database.redis.cache.database' => 14]);
        Cache::flush();
        Queue::swap($this->queueManager);
        Queue::connection()->clear('exports');
        $this->readings(10);
        $export = $this->create('pdf');
        $this->assertSame(1, Queue::connection()->size('exports'));
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'exports', '--once' => true, '--sleep' => 0]);
        $this->assertSame('ready', $export->fresh()->status);
        $this->assertSame(0, Queue::connection()->size('exports'));
        GenerateMeasurementReport::dispatch($export->id)->onQueue('exports');
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'exports', '--once' => true, '--sleep' => 0]);
        $this->assertSame('ready', $export->fresh()->status);
        $this->assertCount(1, Storage::disk('documents')->allFiles('measurement-exports'));
    }

    public function test_revocation_during_upload_discards_the_private_file_before_publishing(): void
    {
        $export = $this->create();
        $disk = Storage::disk('documents');
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('put')->once()->andReturnUsing(function ($path, $stream, $options) use ($disk) {
            $result = $disk->put($path, $stream, $options);
            $this->reader->forceFill(['location_access' => [['type' => 'site', 'id' => Site::create(['name' => 'Other'])->id]]])->save();

            return $result;
        });
        Storage::shouldReceive('disk')->with('documents')->andReturn($proxy);
        app(MeasurementReportExports::class)->generate($export->id);
        $this->assertSame('cancelled', $export->fresh()->status);
        $this->assertSame('reportExports.errors.accessLost', $export->fresh()->error_code);
        $disk->assertMissing(app(MeasurementReportExports::class)->path($export));
    }
}
