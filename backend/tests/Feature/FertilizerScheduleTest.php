<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\LifecycleSetting;
use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\Site;
use App\Models\StorageLocation;
use App\Models\Strain;
use App\Models\User;
use App\Services\FertilizerSchedule;
use App\Services\Inventory;
use App\Services\PlantTracking;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FertilizerScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function period(string $unit, int $start, ?int $end, array $products): array
    {
        return ['id' => (string) Str::uuid(), 'name' => null, 'unit' => $unit, 'start' => $start, 'end' => $end, 'products' => array_map(fn ($id, $dose) => ['inventory_item_id' => $id, 'dose_per_liter' => $dose], array_keys($products), array_values($products))];
    }

    private function configure(array $periods, bool $flush = false): void
    {
        $settings = LifecycleSetting::findOrFail(1);
        $stages = $settings->stages;
        foreach ($stages as &$stage) {
            if ($stage['id'] === 'flowering') {
                $stage['fertilizer_periods'] = $periods;
                $stage['flush'] = $flush;
            }
        }
        unset($stage);
        $settings->update(['stages' => $stages]);
        request()->attributes->remove('lifecycle.stages');
    }

    private function plant(int $days = 56): Plant
    {
        $start = CarbonImmutable::parse('2026-08-01T00:00:00Z');

        return Plant::forceCreate(['creation_key' => (string) Str::uuid(), 'creation_hash' => hash('sha256', 'schedule fixture'), 'strain_id' => Strain::create(['name' => 'Schedule strain'])->id, 'site_id' => Site::create(['name' => 'Schedule site'])->id, 'name' => 'Schedule plant', 'phase' => 'flowering', 'started_at' => $start->subWeeks(4), 'phase_started_at' => $start, 'phase_due_at' => $start->addDays($days), 'duration_snapshot' => ['durations' => ['flowering' => $days, 'flush' => 14]], 'version' => 1]);
    }

    private function products(Plant $plant, string $at): array
    {
        return app(FertilizerSchedule::class)->forPlant($plant, CarbonImmutable::parse($at))['products'];
    }

    public function test_absolute_relative_and_overlapping_periods_have_exact_nonduplicated_boundaries(): void
    {
        $plant = $this->plant();
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $c = (string) Str::uuid();
        $this->configure([$this->period('weeks', 0, 1, [$a => '1']), $this->period('percent', 0, 50, [$b => '2']), $this->period('percent', 50, 100, [$c => '3'])]);
        $this->assertEqualsCanonicalizing([$a, $b], array_column($this->products($plant, '2026-08-07T23:59:59.999999Z'), 'inventory_item_id'));
        $this->assertSame([$b], array_column($this->products($plant, '2026-08-08T00:00:00Z'), 'inventory_item_id'));
        $this->assertSame([$b], array_column($this->products($plant, '2026-08-28T23:59:59.999999Z'), 'inventory_item_id'));
        $this->assertSame([$c], array_column($this->products($plant, '2026-08-29T00:00:00Z'), 'inventory_item_id'));
        $this->assertSame([], $this->products($plant, '2026-09-26T00:00:00Z'));
        $this->assertSame([], $this->products($plant, '2026-07-31T23:59:59Z'));
    }

    public function test_percentages_use_effective_plant_duration_and_fractional_days_without_rounding_gaps(): void
    {
        $plant = $this->plant(7);
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $this->configure([$this->period('percent', 0, 50, [$a => '1']), $this->period('percent', 50, 100, [$b => '2'])]);
        $this->assertSame($a, $this->products($plant, '2026-08-04T11:59:59.999999Z')[0]['inventory_item_id']);
        $this->assertSame($b, $this->products($plant, '2026-08-04T12:00:00Z')[0]['inventory_item_id']);
        $plant->phase_due_at = $plant->phase_started_at->addDays(14);
        $this->assertSame($a, $this->products($plant, '2026-08-04T12:00:00Z')[0]['inventory_item_id']);
        $this->assertSame($b, $this->products($plant, '2026-08-08T00:00:00Z')[0]['inventory_item_id']);
    }

    public function test_same_product_uses_latest_start_then_last_period_without_summing_doses(): void
    {
        $plant = $this->plant();
        $id = (string) Str::uuid();
        $this->configure([$this->period('days', 0, null, [$id => '1']), $this->period('weeks', 1, 2, [$id => '2']), $this->period('days', 7, 14, [$id => '3'])]);
        $this->assertSame([['inventory_item_id' => $id, 'dose_per_liter' => '3']], $this->products($plant, '2026-08-08T00:00:00Z'));
        $this->assertSame([['inventory_item_id' => $id, 'dose_per_liter' => '1']], $this->products($plant, '2026-08-15T00:00:00Z'));
    }

    public function test_flush_short_phases_unknown_starts_and_phase_changes_do_not_invent_products(): void
    {
        $plant = $this->plant();
        $id = (string) Str::uuid();
        $this->configure([$this->period('percent', 0, 100, [$id => '1']), $this->period('days', 70, 80, [(string) Str::uuid() => '2'])], true);
        $this->assertCount(1, $this->products($plant, '2026-09-11T23:59:59.999999Z'));
        $this->assertSame([], $this->products($plant, '2026-09-12T00:00:00Z'));
        $plan = app(FertilizerSchedule::class)->forPlant($plant);
        $this->assertSame('outside', $plan['periods'][1]['status']);
        $plant->phase_started_at = null;
        $this->assertSame([], $this->products($plant, '2026-08-05T00:00:00Z'));
        $this->assertSame('unknown', app(FertilizerSchedule::class)->forPlant($plant)['periods'][0]['status']);
        $plant->phase = 'growth';
        $this->assertSame([], $this->products($plant, '2026-08-05T00:00:00Z'));
    }

    public function test_configuration_normalizes_weeks_and_rejects_invalid_bounds_without_mutation(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $item = InventoryItem::create(['name' => 'Fertilizer', 'type' => 'fertilizer', 'unit' => 'ml']);
        $this->configure([$this->period('weeks', 1, 2, [$item->id => '2'])]);
        $settings = LifecycleSetting::findOrFail(1);
        $input = ['version' => $settings->version, 'durations' => $settings->durations, 'stages' => $settings->stages];
        $this->actingAs($admin)->patchJson('/api/v1/lifecycle-settings', $input)->assertOk();
        $saved = collect($settings->fresh()->stages)->firstWhere('id', 'flowering')['fertilizer_periods'][0];
        $this->assertSame('days', $saved['unit']);
        $this->assertSame(7, $saved['start']);
        $this->assertSame(14, $saved['end']);
        foreach ([['unit' => 'percent', 'start' => 100, 'end' => null], ['start' => 3, 'end' => 2], ['unit' => 'weeks', 'end' => 522], ['products' => []]] as $invalid) {
            $settings->refresh();
            $stages = $settings->stages;
            $index = array_search('flowering', array_column($stages, 'id'), true);
            $stages[$index]['fertilizer_periods'][0] = [...$saved, ...$invalid];
            $this->patchJson('/api/v1/lifecycle-settings', ['version' => $settings->version, 'durations' => $settings->durations, 'stages' => $stages])->assertUnprocessable();
            $this->assertSame($settings->stages, $settings->fresh()->stages);
        }
    }

    public function test_suggestions_at_a_selected_date_do_not_rewrite_recorded_usage_or_stock(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $plant = $this->plant();
        $storage = StorageLocation::create(['name' => 'Fertilizer cabinet']);
        $item = app(Inventory::class)->save($admin, ['name' => 'Historical fertilizer', 'type' => 'fertilizer', 'unit' => 'ml']);
        app(Inventory::class)->record($admin, $item, ['kind' => 'receipt', 'quantity' => '100', 'version' => 1, 'request_id' => (string) Str::uuid(), 'reason' => 'Opening stock', 'destination' => ['id' => $storage->id]]);
        $this->configure([$this->period('days', 0, 7, [$item->id => '2'])]);
        $this->actingAs($admin)->getJson('/api/v1/plants/'.$plant->id.'/consumables?at=2026-08-05T00:00:00Z')->assertOk()->assertJsonPath('data.products.0.quantity', '2')->assertJsonPath('data.schedule.periods.0.active', true);
        app(PlantTracking::class)->water($admin, $plant, ['version' => 1, 'request_id' => (string) Str::uuid(), 'with_products' => true, 'occurred_at' => '2026-08-05T00:00:00Z', 'water_liters' => '1', 'fertilizers' => [['inventory_item_id' => $item->id, 'balance_id' => $item->balances()->sole()->id, 'unit' => 'ml', 'dose_per_liter' => '2']]]);
        $event = PlantEvent::where('kind', 'watered')->sole();
        $snapshot = $event->getRawOriginal('snapshot');
        $this->configure([$this->period('days', 0, null, [$item->id => '7'])]);
        $this->getJson('/api/v1/plants/'.$plant->id.'/consumables?at=2026-08-05T00:00:00Z')->assertOk()->assertJsonPath('data.products.0.quantity', '7');
        $this->assertSame($snapshot, $event->fresh()->getRawOriginal('snapshot'));
        $this->assertSame(98000, $item->fresh()->total_units);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->getJson('/api/v1/plants/'.$plant->id.'/consumables?at=not-a-date')->assertUnprocessable();
        $this->actingAs(User::factory()->create(['location_access' => []]))->getJson('/api/v1/plants/'.$plant->id.'/consumables?at=2026-08-05T00:00:00Z')->assertNotFound();
    }
}
