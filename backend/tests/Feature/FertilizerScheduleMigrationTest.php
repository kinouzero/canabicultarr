<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\LifecycleSetting;
use App\Services\LifecycleDefinition;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FertilizerScheduleMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        DB::table('lifecycle_settings')->where('id', 1)->update(['stages' => json_encode(LifecycleDefinition::defaults(), JSON_THROW_ON_ERROR)]);
        parent::tearDown();
    }

    public function test_whole_stage_doses_and_water_volume_survive_migration_and_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_15_130000_add_timed_fertilizer_periods.php');
        $migration->down();
        $item = InventoryItem::create(['name' => 'Original recipe', 'type' => 'fertilizer', 'unit' => 'ml']);
        $settings = LifecycleSetting::findOrFail(1);
        $stages = $settings->stages;
        $index = array_search('growth', array_column($stages, 'id'), true);
        $products = [['inventory_item_id' => $item->id, 'dose_per_liter' => '2.750']];
        $stages[$index]['fertilizers'] = $products;
        $stages[$index]['water_liters'] = '1.500';
        $settings->update(['stages' => $stages]);
        $itemBefore = $item->fresh()->getAttributes();
        $version = $settings->version;
        $migration->up();
        $period = $settings->fresh()->stages[$index]['fertilizer_periods'][0];
        $this->assertSame($products, $period['products']);
        $this->assertSame(0, $period['start']);
        $this->assertNull($period['end']);
        $this->assertArrayNotHasKey('fertilizers', $settings->fresh()->stages[$index]);
        $this->assertSame('1.500', $settings->fresh()->stages[$index]['water_liters']);
        $this->assertSame($version, $settings->fresh()->version);
        $this->assertSame($itemBefore, $item->fresh()->getAttributes());
        $migration->down();
        $this->assertSame($products, $settings->fresh()->stages[$index]['fertilizers']);
        $this->assertSame('1.500', $settings->fresh()->stages[$index]['water_liters']);
        $migration->up();
        $this->assertSame($products, $settings->fresh()->stages[$index]['fertilizer_periods'][0]['products']);
    }

    public function test_rollback_refuses_to_discard_a_timed_configuration(): void
    {
        $settings = LifecycleSetting::findOrFail(1);
        $stages = $settings->stages;
        $period = ['id' => (string) Str::uuid(), 'name' => 'First week', 'unit' => 'days', 'start' => 0, 'end' => 7, 'products' => [['inventory_item_id' => (string) Str::uuid(), 'dose_per_liter' => '1']]];
        $stages[3]['fertilizer_periods'] = [$period];
        $settings->update(['stages' => $stages]);
        $migration = require database_path('migrations/2026_11_15_130000_add_timed_fertilizer_periods.php');
        try {
            $migration->down();
            $this->fail('Timed periods must be retained.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Retain timed fertilizer', $error->getMessage());
        }
        $this->assertSame($period, $settings->fresh()->stages[3]['fertilizer_periods'][0]);
    }
}
