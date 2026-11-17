<?php

namespace Tests\Feature;

use App\Models\MeasurementReportExport;
use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Services\CultureOverview;
use App\Services\MeasurementReportExports;
use App\Services\PlantTracking;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CultureOverviewExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['queue.default' => 'database', 'documents.disk' => 'documents']);
        Storage::fake('documents');
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function input(string $format = 'csv'): array
    {
        return ['format' => $format, 'locale' => 'fr', 'timezone' => 'Europe/Paris', 'request_key' => (string) Str::uuid(), 'days' => 30, 'sections' => ['summary', 'stages', 'activity', 'locations', 'strains', 'upcoming']];
    }

    private function plant(User $actor, Site $site, string $strainName): Plant
    {
        $strain = Strain::create(['name' => $strainName]);

        return app(PlantTracking::class)->create($actor, ['strain_id' => $strain->id, 'request_id' => (string) Str::uuid(), 'location' => ['type' => 'site', 'id' => $site->id]]);
    }

    public function test_csv_export_filters_site_and_sections_preserves_decimals_and_neutralizes_formulas(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $a = Site::create(['name' => 'Visible site']);
        $b = Site::create(['name' => 'Other site']);
        $plant = $this->plant($admin, $a, '=HYPERLINK("fictional")');
        $plant->forceFill(['harvest_weight_grams' => '125.50'])->save();
        $this->plant($admin, $b, 'Hidden strain');
        $input = [...$this->input(), 'site_id' => $a->id, 'sections' => ['summary', 'strains']];
        $id = $this->actingAs($admin)->postJson('/api/v1/culture/overview/exports', $input)->assertAccepted()->json('data.id');
        $this->postJson('/api/v1/culture/overview/exports', $input)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/culture/overview/exports', [...$input, 'days' => 7])->assertConflict();
        $service = app(MeasurementReportExports::class);
        $service->generate($id);
        $export = MeasurementReportExport::findOrFail($id);
        $this->assertSame('ready', $export->status);
        $csv = Storage::disk('documents')->get($service->path($export));
        $this->assertStringContainsString('Visible site', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('125.50', $csv);
        $this->assertStringNotContainsString('Hidden strain', $csv);
        $this->assertStringNotContainsString('Activité enregistrée', $csv);
        $this->getJson('/api/v1/culture/overview/exports')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/api/v1/report-exports/'.$id.'/download')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->actingAs(User::factory()->create())->get('/api/v1/report-exports/'.$id.'/download')->assertForbidden();
        $this->actingAs($admin);
        $admin->forceFill(['session_version' => $admin->session_version + 1])->save();
        $this->get('/api/v1/report-exports/'.$id.'/download')->assertUnauthorized();
    }

    public function test_pdf_is_generated_with_charts_and_permissions_are_rechecked_by_the_worker(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => '<script>alert(1)</script>']);
        $this->plant($admin, $site, 'Example strain');
        $service = app(MeasurementReportExports::class);
        $export = $service->create($admin, null, [...$this->input('pdf'), 'site_id' => $site->id]);
        $service->generate($export->id);
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $pdf = Storage::disk('documents')->get($service->path($export));
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $next = $service->create($admin, null, $this->input('pdf'));
        $admin->forceFill(['is_active' => false])->save();
        $service->generate($next->id);
        $this->assertSame('cancelled', $next->fresh()->status);
        Storage::disk('documents')->assertMissing($service->path($next));
    }

    public function test_export_rejects_inaccessible_sites_invalid_inputs_and_revoked_access(): void
    {
        $this->postJson('/api/v1/culture/overview/exports', $this->input())->assertUnauthorized();
        $site = Site::create(['name' => 'Hidden site']);
        $user = User::factory()->create();
        $visible = Site::create(['name' => 'Visible site']);
        $user->forceFill(['location_access' => [['type' => 'site', 'id' => $visible->id]]])->save();
        $this->actingAs($user)->postJson('/api/v1/culture/overview/exports', [...$this->input(), 'site_id' => $site->id])->assertNotFound();
        $this->postJson('/api/v1/culture/overview/exports', [...$this->input(), 'days' => 60, 'sections' => ['invalid']])->assertUnprocessable();
        $this->postJson('/api/v1/culture/overview/exports', [...$this->input(), 'sections' => []])->assertUnprocessable();
        Gate::define('dashboard.view', fn () => false);
        $this->postJson('/api/v1/culture/overview/exports', $this->input())->assertForbidden();
    }

    public function test_oversized_aggregate_export_fails_without_publishing_partial_data(): void
    {
        $user = User::factory()->create();
        $service = app(MeasurementReportExports::class);
        $export = $service->create($user, null, [...$this->input('pdf'), 'sections' => ['strains']]);
        $this->mock(CultureOverview::class)->shouldReceive('read')->once()->andReturn([
            'plants' => [], 'strains' => array_fill(0, 501, ['name' => 'Fictional', 'active' => 1, 'harvested' => 0, 'harvestWeightGrams' => '0.00']),
        ]);
        $service->generate($export->id);
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame('reportExports.errors.tooLarge', $export->fresh()->error_code);
        Storage::disk('documents')->assertMissing($service->path($export));
    }
}
