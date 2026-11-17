<?php

namespace Tests\Feature;

use App\Models\AlertEpisode;
use App\Models\MeasurementType;
use App\Models\Site;
use App\Models\User;
use App\Services\AssetAdministration;
use App\Services\MonitoringAlerts;
use App\Services\SensorAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->travelTo(now()->startOfSecond());
    }

    private function episode(): AlertEpisode
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $asset = app(AssetAdministration::class)->save($admin, ['name' => 'Fictional Inbox Device']);
        $type = MeasurementType::create(['name' => 'Fictional Type', 'code' => 'inbox', 'unit' => '%']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => 'Fictional Probe', 'device_id' => $asset->id, 'measurement_type_id' => $type->id, 'channel_key' => 'probe']);
        $rule = app(MonitoringAlerts::class)->save($admin, ['name' => 'Fictional Dry Alert', 'sensor_id' => $sensor->id, 'kind' => 'threshold', 'severity' => 'warning', 'minimum' => '35', 'max_age_seconds' => 300]);

        return AlertEpisode::create(['alert_rule_id' => $rule->id, 'rule_snapshot' => ['name' => $rule->name, 'sensor' => ['name' => $sensor->name]], 'severity' => 'warning', 'reason' => 'below_minimum', 'opened_at' => now()->subMinute(), 'last_detected_at' => now()]);
    }

    public function test_inbox_requires_an_active_current_session_and_valid_read_cursor(): void
    {
        $this->getJson('/api/v1/notifications/inbox')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/inbox/read', ['read_through' => now()->toISOString()])->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/notifications/inbox')->assertOk()->assertJsonPath('data.unreadCount', 0);
        $this->postJson('/api/v1/notifications/inbox/read', ['read_through' => now()->addDay()->toISOString()])->assertUnprocessable();
        $this->postJson('/api/v1/notifications/inbox/read', ['read_through' => 'yesterday'])->assertUnprocessable();
        $this->getJson('/api/v1/notifications/inbox?page=0')->assertUnprocessable();
        $user->forceFill(['is_active' => false])->save();
        $this->getJson('/api/v1/notifications/inbox')->assertUnauthorized();
    }

    public function test_read_state_is_personal_and_new_recovery_remains_unread_after_an_older_cursor(): void
    {
        $episode = $this->episode();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $feed = $this->actingAs($first)->getJson('/api/v1/notifications/inbox')->assertOk()->assertJsonPath('data.unreadCount', 1)->assertJsonPath('data.items.0.unread', true);
        $through = $feed->json('data.readThrough');
        $this->postJson('/api/v1/notifications/inbox/read', ['read_through' => $through])->assertNoContent();
        $this->getJson('/api/v1/notifications/inbox')->assertOk()->assertJsonPath('data.unreadCount', 0)->assertJsonPath('data.items.0.unread', false);
        $this->assertNull($episode->fresh()->acknowledged_at);
        $this->actingAs($second)->getJson('/api/v1/notifications/inbox?summary=1')->assertOk()->assertJsonPath('data.unreadCount', 1)->assertJsonPath('data.items', []);
        $this->travel(1)->seconds();
        $episode->update(['resolved_at' => now(), 'resolution' => 'within_bounds']);
        $this->actingAs($first)->postJson('/api/v1/notifications/inbox/read', ['read_through' => $through])->assertNoContent();
        $this->getJson('/api/v1/notifications/inbox')->assertOk()->assertJsonPath('data.unreadCount', 1)->assertJsonPath('data.items.0.event', 'recovered');
        $this->postJson('/api/v1/notifications/inbox/read', ['read_through' => now()->toISOString()])->assertNoContent();
        $this->postJson('/api/v1/notifications/inbox/read', ['read_through' => $through])->assertNoContent();
        $this->getJson('/api/v1/notifications/inbox')->assertOk()->assertJsonPath('data.unreadCount', 0);
    }

    public function test_counts_and_paginated_items_respect_captured_location_access(): void
    {
        $episode = $this->episode();
        $site = Site::create(['name' => 'Fictional Allowed Site']);
        $snapshot = $episode->rule_snapshot;
        $snapshot['location'] = ['site' => ['id' => $site->id, 'name' => $site->name]];
        $episode->update(['rule_snapshot' => $snapshot, 'resolved_at' => now()->subSeconds(20)]);
        for ($i = 1; $i <= 20; $i++) {
            $copy = $episode->replicate();
            $copy->opened_at = now()->subMinutes($i + 1);
            $copy->resolved_at = now()->subSeconds(20 + $i);
            $copy->save();
        }
        $hidden = $episode->replicate();
        $hidden->rule_snapshot = ['name' => 'Hidden Alert'];
        $hidden->save();
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $site->id]]]);
        $this->actingAs($reader)->getJson('/api/v1/notifications/inbox')->assertOk()->assertJsonPath('data.unreadCount', 21)->assertJsonCount(20, 'data.items')->assertJsonPath('data.lastPage', 2)->assertJsonPath('data.items.0.id', $episode->id)->assertDontSee('Hidden Alert');
        $this->getJson('/api/v1/notifications/inbox?page=2')->assertOk()->assertJsonCount(1, 'data.items');
        $reader->forceFill(['location_access' => []])->save();
        $this->getJson('/api/v1/notifications/inbox')->assertOk()->assertJsonPath('data.unreadCount', 0)->assertJsonPath('data.items', []);
    }
}
