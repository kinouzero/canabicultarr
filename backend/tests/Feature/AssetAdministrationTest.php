<?php

namespace Tests\Feature;

use App\Enums\AssetCategory;
use App\Models\Asset;
use App\Models\MeasurementType;
use App\Models\Room;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use App\Services\AssetAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function asset(array $data = []): Asset
    {
        return Asset::create(['name' => 'Test Asset', ...$data]);
    }

    private function hierarchy(): array
    {
        $site = Site::create(['name' => 'Asset Test Site']);
        $room = new Room(['name' => 'Asset Test Room']);
        $room->site()->associate($site);
        $room->save();
        $zone = new Zone(['name' => 'Asset Test Zone']);
        $zone->room()->associate($room);
        $zone->save();

        return [$site, $room, $zone];
    }

    public function test_add_location_is_idempotent_and_keeps_previous_assignments(): void
    {
        $site = Site::create(['name' => 'Existing']);
        $next = Site::create(['name' => 'Additional']);
        $this->actingAs(User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]));
        $id = $this->postJson('/api/v1/assets', ['name' => 'Fixture', 'locations' => [['type' => 'site', 'id' => $site->id]]])->assertCreated()->json('data.id');
        $addition = ['type' => 'site', 'id' => $next->id];

        $this->patchJson('/api/v1/assets/'.$id, ['add_location' => $addition])->assertOk()->assertJsonCount(2, 'data.locations');
        $this->patchJson('/api/v1/assets/'.$id, ['add_location' => $addition])->assertOk()->assertJsonCount(2, 'data.locations');
        $this->patchJson('/api/v1/assets/'.$id, ['add_location' => $addition, 'locations' => [['type' => 'site', 'id' => $site->id]]])->assertUnprocessable();
        $this->assertDatabaseCount('asset_locations', 2);
    }

    public function test_register_counts_channels_and_searches_them_without_crossing_location_scope(): void
    {
        [$site] = $this->hierarchy();
        $private = Site::create(['name' => 'Private hardware site']);
        $manual = $this->asset(['name' => 'Manual hardware']);
        $this->placeAsset($manual, ['site_id' => $site->id]);
        $device = $this->asset(['name' => 'Weather station']);
        $this->placeAsset($device, ['site_id' => $site->id]);
        $hidden = $this->asset(['name' => 'Hidden hardware']);
        $this->placeAsset($hidden, ['site_id' => $private->id]);
        $type = MeasurementType::create(['name' => 'Ambient temperature', 'code' => 'ambient_temperature', 'unit' => '°C', 'decimal_places' => 1]);
        $channel = function (Asset $asset, string $name, string $key, bool $enabled = true) use ($type) {
            $sensor = new Sensor(['name' => $name, 'channel_key' => $key, 'is_enabled' => $enabled]);
            $sensor->device()->associate($asset);
            $sensor->measurementType()->associate($type);
            $sensor->save();
        };
        $channel($device, 'Canopy temperature', 'canopy_temp');
        $channel($device, 'Backup reading', 'backup', false);
        $channel($hidden, 'Private needle', 'private_needle');
        $member = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $this->actingAs($member)->getJson('/api/v1/assets?search=Weather')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channelsCount', 2)->assertJsonPath('data.0.enabledChannelsCount', 1);
        $this->getJson('/api/v1/assets?search=Manual')->assertJsonPath('data.0.channelsCount', 0);
        foreach (['Canopy', 'canopy_temp', 'Ambient temperature', 'ambient_temperature'] as $search) {
            $this->getJson('/api/v1/assets?search='.urlencode($search))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $device->id);
        }
        $this->getJson('/api/v1/assets?search=private_needle')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/assets?search=ambient_temperature&category=fan')->assertJsonCount(0, 'data');
    }

    public function test_guests_and_members_have_correct_register_and_history_permissions(): void
    {
        $asset = $this->asset();
        $this->getJson('/api/v1/assets')->assertUnauthorized();
        $this->getJson("/api/v1/assets/$asset->id/assignments")->assertUnauthorized();
        $this->postJson('/api/v1/assets', ['name' => 'Denied'])->assertUnauthorized();
        $this->patchJson('/api/v1/assets/'.$asset->id, ['name' => 'Denied'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/assets')->assertOk();
        $this->getJson("/api/v1/assets/$asset->id/assignments")->assertOk();
        $this->postJson('/api/v1/assets', ['name' => 'Denied'])->assertForbidden();
        $this->patchJson('/api/v1/assets/'.$asset->id, ['name' => 'Denied'])->assertForbidden();
    }

    public function test_creation_uses_server_uuid_defaults_and_safe_serialization(): void
    {
        $this->actingAs($this->admin());
        $forged = (string) Str::uuid();
        $result = $this->postJson('/api/v1/assets', [
            'id' => $forged, 'name' => ' Test Device ', 'name_key' => 'forged',
            'site_id' => $forged, 'asset_tag' => ' DEV-01 ', 'manufacturer' => 'Example Maker',
            'model' => 'Model A', 'serial_number' => 'SN-1', 'description' => 'Notes', 'locations' => [],
        ])->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.locations', [])
            ->assertJsonPath('data.name', 'Test Device')->assertJsonPath('data.assetTag', 'DEV-01')
            ->assertJsonPath('data.serialNumber', 'SN-1')->assertJsonMissingPath('data.name_key')
            ->assertJsonMissingPath('data.asset_tag_key')->assertJsonMissingPath('data.site_id')->json('data');
        $this->assertTrue(Str::isUuid($result['id']));
        $this->assertNotSame($forged, $result['id']);
        $this->assertDatabaseCount('asset_assignments', 0);
        $this->assertDatabaseHas('assets', ['id' => $result['id']]);
        $this->assertDatabaseCount('asset_locations', 0);
        $this->postJson('/api/v1/assets', ['name' => 'Test Device'])->assertCreated();
    }

    public function test_categories_are_editable_without_replacing_hardware_or_its_channels(): void
    {
        $this->actingAs($this->admin());
        foreach (AssetCategory::cases() as $category) {
            $asset = $this->postJson('/api/v1/assets', ['name' => 'Manual '.$category->value, 'category' => $category->value])
                ->assertCreated()->assertJsonPath('data.category', $category->value)->json('data');
            $this->getJson('/api/v1/assets?category='.$category->value)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $asset['id']);
        }
        $type = MeasurementType::create(['name' => 'Power', 'code' => 'power', 'unit' => 'W']);
        $channel = $this->postJson('/api/v1/sensors', ['name' => 'Power channel', 'device_id' => $asset['id'], 'measurement_type_id' => $type->id, 'channel_key' => 'power'])->assertCreated()->json('data');
        $this->patchJson('/api/v1/assets/'.$asset['id'], ['category' => 'socket'])->assertOk()
            ->assertJsonPath('data.id', $asset['id'])->assertJsonPath('data.category', 'socket')->assertJsonMissingPath('data.kind');
        $this->getJson('/api/v1/sensors?device_id='.$asset['id'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $channel['id']);
        $this->assertDatabaseCount('asset_assignments', 0);
        $this->assertDatabaseCount('home_assistant_links', 0);
        $this->patchJson('/api/v1/assets/'.$asset['id'], ['category' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->getJson('/api/v1/assets?category=invalid')->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->actingAs(User::factory()->create());
        $this->patchJson('/api/v1/assets/'.$asset['id'], ['category' => 'fan'])->assertForbidden();
    }

    public function test_optional_tags_are_case_insensitively_unique_and_null_tags_repeat(): void
    {
        $this->actingAs($this->admin());
        $this->postJson('/api/v1/assets', ['name' => 'One', 'asset_tag' => ' AB-01 '])->assertCreated();
        $this->postJson('/api/v1/assets', ['name' => 'Two', 'asset_tag' => 'ab-01'])->assertConflict()->assertJsonPath('error.code', 'assets.errors.tagTaken');
        $this->postJson('/api/v1/assets', ['name' => 'Zero', 'asset_tag' => '0'])->assertCreated();
        $this->postJson('/api/v1/assets', ['name' => 'Zero Duplicate', 'asset_tag' => '0'])->assertConflict();
        foreach ([null, ''] as $tag) {
            $this->postJson('/api/v1/assets', ['name' => 'Untagged', 'asset_tag' => $tag])->assertCreated()->assertJsonPath('data.assetTag', null);
        }
    }

    public function test_invalid_types_status_metadata_and_locations_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $base = ['name' => 'Asset'];
        foreach ([['name' => ' '], ['name' => str_repeat('x', 121)], ['category' => 'invalid'], ['status' => 'online'],
            ['asset_tag' => str_repeat('x', 81)], ['description' => str_repeat('x', 2001)], ['model' => str_repeat('x', 121)],
            ['locations' => [[]]], ['locations' => [['type' => 'site']]], ['locations' => [['type' => 'user', 'id' => (string) Str::uuid()]]],
            ['locations' => [['type' => 'site', 'id' => 'bad']]], ['locations' => [['type' => 'site', 'id' => (string) Str::uuid(), 'extra' => true]]]] as $bad) {
            $this->postJson('/api/v1/assets', [...$base, ...$bad])->assertUnprocessable();
        }
        $this->postJson('/api/v1/assets', [...$base, 'locations' => [['type' => 'site', 'id' => (string) Str::uuid()]]])->assertNotFound();
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_assignments_resolve_each_level_and_removal_creates_history(): void
    {
        [$site, $room, $zone] = $this->hierarchy();
        $this->actingAs($admin = $this->admin());
        $id = $this->postJson('/api/v1/assets', ['name' => 'Movable', 'locations' => [['type' => 'site', 'id' => $site->id]]])
            ->assertCreated()->assertJsonPath('data.locations.0.site.name', $site->name)->json('data.id');
        foreach ([['room', $room], ['zone', $zone]] as [$type, $location]) {
            $this->patchJson('/api/v1/assets/'.$id, ['locations' => [['type' => $type, 'id' => $location->id]]])
                ->assertOk()->assertJsonPath('data.locations.0.type', $type)->assertJsonPath('data.locations.0.id', $location->id)
                ->assertJsonPath('data.locations.0.site.id', $site->id)->assertJsonPath('data.locations.0.room.id', $room->id);
        }
        $this->assertDatabaseHas('asset_locations', ['asset_id' => $id, 'site_id' => null, 'room_id' => null, 'zone_id' => $zone->id]);
        $this->patchJson('/api/v1/assets/'.$id, ['locations' => []])->assertOk()->assertJsonPath('data.locations', []);
        $history = $this->getJson('/api/v1/assets/'.$id.'/assignments')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.locations', [])->assertJsonPath('data.0.changedBy', $admin->uuid)
            ->assertJsonMissingPath('data.0.user_id')->assertJsonMissingPath('data.0.user')->json('data');
        $this->assertSame('zone', $history[1]['locations'][0]['type']);
        $this->assertSame('room', $history[2]['locations'][0]['type']);
        $this->assertSame('site', $history[3]['locations'][0]['type']);
    }

    public function test_metadata_changes_and_same_assignment_do_not_add_history(): void
    {
        [$site] = $this->hierarchy();
        $this->actingAs($this->admin());
        $id = $this->postJson('/api/v1/assets', ['name' => 'Asset', 'locations' => [['type' => 'site', 'id' => $site->id]]])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/assets/'.$id, ['name' => 'Renamed', 'status' => 'maintenance'])->assertOk()->assertJsonPath('data.locations.0.id', $site->id);
        $this->patchJson('/api/v1/assets/'.$id, ['locations' => [['type' => 'site', 'id' => $site->id]]])->assertOk();
        $this->assertDatabaseCount('asset_assignments', 1);
        $this->patchJson('/api/v1/assets/'.$id, ['category' => 'fan'])->assertOk()->assertJsonPath('data.category', 'fan');
        $this->patchJson('/api/v1/assets/'.$id, ['status' => 'retired'])->assertOk()->assertJsonMissingPath('data.kind');
    }

    public function test_tag_conflict_rolls_back_location_and_history_changes(): void
    {
        [$site] = $this->hierarchy();
        $this->asset(['asset_tag' => 'taken']);
        $other = $this->asset(['name' => 'Other', 'asset_tag' => 'free']);
        $this->actingAs($this->admin())->patchJson('/api/v1/assets/'.$other->id, [
            'asset_tag' => 'TAKEN', 'locations' => [['type' => 'site', 'id' => $site->id]],
        ])->assertConflict();
        $this->assertSame('free', $other->fresh()->asset_tag);
        $this->assertSame([], $other->fresh()->locationList());
        $this->assertDatabaseCount('asset_assignments', 0);
    }

    public function test_search_filters_and_pagination_do_not_expose_normalized_fields(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->asset(['name' => sprintf('Asset %02d', $i)]);
        }
        $match = $this->asset(['name' => 'Literal _% Device', 'category' => 'sensor', 'status' => 'maintenance', 'asset_tag' => 'Needle-Tag', 'serial_number' => 'Serial-Needle']);
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/assets')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23);
        $this->getJson('/api/v1/assets?page=2')->assertJsonCount(3, 'data');
        foreach (['_%', 'needle-tag', 'SERIAL-NEEDLE'] as $search) {
            $this->getJson('/api/v1/assets?search='.urlencode($search))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
        }
        $this->getJson('/api/v1/assets?category=sensor&status=maintenance')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/assets?category=fan&status=maintenance')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/assets?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/assets?status=online')->assertUnprocessable();
    }

    public function test_history_is_scoped_to_asset_and_paginated(): void
    {
        [$site] = $this->hierarchy();
        $admin = $this->admin();
        $service = app(AssetAdministration::class);
        $first = $this->asset();
        $second = $this->asset(['name' => 'Other']);
        for ($i = 0; $i < 23; $i++) {
            $service->save($admin, ['locations' => $i % 2 === 0 ? [['type' => 'site', 'id' => $site->id]] : []], $first);
        }
        $service->save($admin, ['locations' => [['type' => 'site', 'id' => $site->id]]], $second);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/assets/'.$first->id.'/assignments')
            ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23);
        $this->getJson('/api/v1/assets/'.$first->id.'/assignments?page=2')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/assets/'.$second->id.'/assignments')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/assets/not-a-uuid/assignments')->assertNotFound();
    }

    public function test_inactive_and_revoked_sessions_cannot_read_or_write(): void
    {
        $asset = $this->asset();
        $user = $this->admin();
        $user->is_active = false;
        $user->save();
        $this->actingAs($user)->getJson('/api/v1/assets')->assertUnauthorized();
        $user->forceFill(['is_active' => true, 'session_version' => 1])->save();
        $this->actingAs($user)->withSession(['auth_version' => 0])->patchJson('/api/v1/assets/'.$asset->id, ['status' => 'retired'])->assertUnauthorized();
    }

    public function test_service_rechecks_the_current_actor(): void
    {
        $admin = $this->admin();
        $stale = clone $admin;
        $admin->permission_role_ids = [AccessProfileSeeder::defaultProfile()->id];
        $admin->save();
        try {
            app(AssetAdministration::class)->save($stale, ['name' => 'Denied']);
            $this->fail('Stale role must not permit writes.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('assets', 0);
        }
    }

    public function test_location_foreign_keys_protect_current_and_historical_assignments(): void
    {
        $site = Site::create(['name' => 'History-only site']);
        $admin = $this->admin();
        $service = app(AssetAdministration::class);
        $asset = $service->save($admin, ['name' => 'Asset', 'locations' => [['type' => 'site', 'id' => $site->id]]]);
        $service->save($admin, ['locations' => []], $asset);
        try {
            DB::transaction(fn () => $site->delete());
            $this->fail('Historical location must be preserved.');
        } catch (QueryException) {
            $this->assertTrue($site->newQuery()->whereKey($site->id)->exists());
        }
    }

    public function test_audit_events_do_not_include_inventory_values(): void
    {
        Log::spy();
        $admin = $this->admin();
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Private name', 'serial_number' => 'Private serial']);
        Log::shouldHaveReceived('notice')->withArgs(fn ($event, $context) => $event === 'asset.created'
            && $context['actor_uuid'] === $admin->uuid && $context['asset_uuid'] === $asset->id
            && in_array('name', $context['fields'], true) && in_array('serial_number', $context['fields'], true)
            && ! str_contains(json_encode($context), 'Private'))->once();
    }
}
