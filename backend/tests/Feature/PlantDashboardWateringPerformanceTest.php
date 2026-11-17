<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetContext;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Plant;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\AssetContexts;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlantDashboardWateringPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_mixed_site_retains_exact_counts_and_bounded_dashboard_work(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $this->actingAs($user);
        $site = Site::create(['name' => 'Watering benchmark']);
        $strain = Strain::create(['name' => 'Benchmark strain']);
        $fixtureNow = now();
        $this->travel(-6)->days();
        $device = Asset::create(['name' => 'Benchmark probe']);
        $this->placeAsset($device, ['site_id' => $site->id]);
        $type = MeasurementType::create(['code' => 'benchmark_moisture', 'name' => 'Moisture', 'unit' => '%', 'nature' => 'substrate_moisture']);
        $wet = new Sensor(['name' => 'Wet', 'channel_key' => 'wet', 'expected_interval_seconds' => 3600]);
        $wet->asset_id = $device->id;
        $wet->measurement_type_id = $type->id;
        $wet->save();
        $this->travelTo($fixtureNow);
        for ($i = 0; $i < 100; $i++) {
            Measurement::create(['sensor_id' => $wet->id, 'value' => '80', 'unit' => '%', 'observed_at' => now()->subSeconds($i), 'received_at' => now(), 'location_snapshot' => ['site' => ['id' => $site->id, 'name' => $site->name]]]);
        }
        $timersOnly = getenv('DASHBOARD_WATERING_TIMERS_ONLY') === '1';
        $plants = [];
        $links = [];
        for ($i = 0; $i < 1200; $i++) {
            $id = (string) Str::uuid();
            $plants[] = ['id' => $id, 'strain_id' => $strain->id, 'name' => 'Plant '.$i, 'name_key' => 'plant '.$i, 'phase' => 'growth', 'started_at' => now()->subDays(5), 'phase_started_at' => now()->subDays(5), 'phase_due_at' => $i % 4 === 0 ? now()->subDay() : now()->addDay(), 'site_id' => $site->id, 'creation_key' => (string) Str::uuid(), 'creation_hash' => str_repeat('0', 64), 'duration_snapshot' => '{}'];
            if (! $timersOnly && $i % 2 === 0) {
                $links[] = ['plant_id' => $id, 'asset_id' => $device->id];
            }
        }
        foreach (array_chunk($plants, 100) as $chunk) {
            DB::table('plants')->insert($chunk);
        }
        foreach (array_chunk($links, 100) as $chunk) {
            DB::table('plant_device')->insert($chunk);
        }
        $this->travel(-5)->days();
        app(AssetContexts::class)->capture($device->id);
        $this->travelTo($fixtureNow);
        $context = AssetContext::where('asset_id', $device->id)->orderByDesc('effective_at')->firstOrFail();
        DB::table('measurements')->update(['asset_context_id' => $context->id, 'provenance_snapshot' => json_encode(['status' => 'recorded', 'assetId' => $device->id])]);
        $hydrated = 0;
        Plant::retrieved(function (Plant $plant) use (&$hydrated) {
            if ($plant->name !== null) {
                $hydrated++;
            }
        });
        $url = '/api/v1/sites/'.$site->id.'/plant-dashboard?grouped=1&metadata_only=1';
        $results = [];
        foreach ([false, true] as $dueOnly) {
            $times = [];
            for ($run = 0; $run < 3; $run++) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                memory_reset_peak_usage();
                $started = hrtime(true);
                $response = $this->getJson($url.($dueOnly ? '&due_only=1' : ''))->assertOk();
                $times[] = (hrtime(true) - $started) / 1e6;
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $due = $timersOnly ? 1200 : 900;
                $response->assertJsonCount(0, 'data')->assertJsonPath('summary.due', $due)->assertJsonPath('summary.plants', $dueOnly ? $due : 1200)->assertJsonPath('meta.total', $dueOnly ? $due : 1200);
            }
            $this->assertLessThan(25, count($queries));
            $this->assertLessThan(100, max(array_map(fn ($q) => count($q['bindings']), $queries)));
            sort($times);
            $results[$dueOnly ? 'due_only' : 'summary'] = ['milliseconds_median' => round($times[1], 2), 'queries' => count($queries), 'sql_milliseconds' => round(array_sum(array_column($queries, 'time')), 2), 'peak_bytes' => memory_get_peak_usage(true), 'response_bytes' => strlen($response->getContent()), 'max_bindings' => max(array_map(fn ($q) => count($q['bindings']), $queries))];
        }
        $this->assertSame(0, $hydrated, 'Metadata must not hydrate plant records.');
        if (getenv('DASHBOARD_WATERING_BENCHMARK')) {
            file_put_contents('/tmp/canabicultarr-watering-'.DB::getDriverName().'-'.getenv('DASHBOARD_WATERING_BENCHMARK').'.json', json_encode($results, JSON_PRETTY_PRINT));
        }
    }
}
