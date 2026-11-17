<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UiPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class UiPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private array $keys = [];

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            Redis::del($key, $key.':bytes');
        }
        parent::tearDown();
    }

    private function account(): User
    {
        $user = User::factory()->create();
        $this->keys[] = app(UiPreferences::class)->key($user);

        return $user;
    }

    public function test_accounting_recovers_after_metadata_eviction_and_does_not_drift_on_overwrite(): void
    {
        $user = $this->account();
        $preferences = app(UiPreferences::class);
        $entries = [['key' => 'sorting:plants:sort', 'value' => 'name_asc']];
        $preferences->update($user, $entries);
        $budget = Redis::get($preferences->key($user).':bytes');
        $preferences->update($user, $entries);
        $this->assertSame($budget, Redis::get($preferences->key($user).':bytes'));
        Redis::del($preferences->key($user).':bytes');
        $preferences->update($user, $entries);
        $this->assertSame($budget, Redis::get($preferences->key($user).':bytes'));
        $this->assertSame(['sorting:plants:sort' => 'name_asc'], $preferences->read($user));
    }

    public function test_access_requires_an_active_current_session(): void
    {
        $this->getJson('/api/v1/account/ui-preferences')->assertUnauthorized();
        $this->patchJson('/api/v1/account/ui-preferences', ['entries' => []])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_active' => false]))->getJson('/api/v1/account/ui-preferences')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['session_version' => 1]))->withSession(['auth_version' => 0])->getJson('/api/v1/account/ui-preferences')->assertUnauthorized();
    }

    public function test_redis_preferences_are_isolated_durable_and_merge_independent_fields(): void
    {
        $one = $this->account();
        $two = $this->account();
        $this->assertSame('ui-preferences:'.$one->uuid, app(UiPreferences::class)->key($one));
        $path = '/api/v1/account/ui-preferences';
        $this->actingAs($one)->getJson($path)->assertOk()->assertJsonPath('data', []);
        $this->patchJson($path, ['entries' => [
            ['key' => 'sorting:plants:sort', 'value' => 'due_asc'],
            ['key' => 'pagination:plants:size', 'value' => 50],
            ['key' => 'layout:plants:mode', 'value' => 'rows'],
            ['key' => 'layout:strains:mode', 'value' => 'cards'],
            ['key' => 'disclosures:dashboard:site:zone:one', 'value' => true],
        ]])->assertNoContent();
        $this->patchJson($path, ['entries' => [
            ['key' => 'disclosures:dashboard:site:zone:two', 'value' => false],
            ['key' => 'measurements:pins', 'value' => ['sensor-one']],
            ['key' => 'measurements:site', 'value' => ''],
        ]])->assertNoContent();
        $this->getJson($path)->assertOk()->assertJsonPath('data.layout:plants:mode', 'rows')->assertJsonPath('data.layout:strains:mode', 'cards')->assertJsonPath('data.pagination:plants:size', 50)->assertJsonPath('data.sorting:plants:sort', 'due_asc')->assertJsonPath('data.disclosures:dashboard:site:zone:one', true)->assertJsonPath('data.disclosures:dashboard:site:zone:two', false)->assertJsonPath('data.measurements:pins', ['sensor-one'])->assertJsonPath('data.measurements:site', '');
        $this->assertSame(-1, Redis::ttl(app(UiPreferences::class)->key($one)));
        $this->actingAs($two)->getJson($path)->assertJsonPath('data', []);
        $this->patchJson($path, ['entries' => [['key' => 'sorting:plants:sort', 'value' => 'name_desc']]])->assertNoContent();
        $this->actingAs($one)->getJson($path)->assertJsonPath('data.sorting:plants:sort', 'due_asc');
    }

    public function test_payloads_are_bounded_and_cannot_target_another_account(): void
    {
        $one = $this->account();
        $two = $this->account();
        $this->actingAs($one);
        foreach ([
            ['entries' => [['key' => 'sorting:plants:sort', 'value' => str_repeat('x', 1001)]]],
            ['entries' => [['key' => 'sorting:plants:sort', 'value' => ['nested' => 'object']]]],
            ['entries' => [['key' => 'auth:role', 'value' => 'admin']]],
            ['entries' => [['key' => 'sorting:plants:sort', 'value' => true]], 'user_id' => $two->id],
            ['entries' => array_fill(0, 101, ['key' => 'sorting:plants:sort', 'value' => 'name_asc'])],
        ] as $input) {
            $this->patchJson('/api/v1/account/ui-preferences', $input)->assertUnprocessable();
        }
        $this->getJson('/api/v1/account/ui-preferences')->assertJsonPath('data', []);
    }

    public function test_account_field_budget_rejects_the_whole_batch_without_evicting_preferences(): void
    {
        $user = $this->account();
        $key = app(UiPreferences::class)->key($user);
        $values = [];
        for ($i = 0; $i < UiPreferences::MAX_FIELDS; $i++) {
            $values['disclosures:fixture:'.$i] = 'true';
        }
        Redis::hmset($key, $values);
        $this->actingAs($user)->patchJson('/api/v1/account/ui-preferences', ['entries' => [
            ['key' => 'disclosures:fixture:0', 'value' => false],
            ['key' => 'sorting:new:sort', 'value' => 'name_asc'],
        ]])->assertUnprocessable()->assertJsonPath('errors.entries.0', 'collection.preferenceLimit');
        $this->assertSame('true', Redis::hget($key, 'disclosures:fixture:0'));
        $this->assertSame(UiPreferences::MAX_FIELDS, Redis::hlen($key));
        $this->patchJson('/api/v1/account/ui-preferences', ['entries' => [
            ['key' => 'disclosures:fixture:0', 'value' => false],
        ]])->assertNoContent();
        $this->assertSame('false', Redis::hget($key, 'disclosures:fixture:0'));
    }

    public function test_account_byte_budget_is_atomic_and_can_be_reduced_without_losing_other_fields(): void
    {
        $user = $this->account();
        $key = app(UiPreferences::class)->key($user);
        $large = 'measurements:fixture';
        $small = 'sorting:plants:sort';
        $value = json_encode(str_repeat('x', UiPreferences::MAX_BYTES - strlen($large) - strlen($small) - strlen('"old"') - 2));
        Redis::hmset($key, [$large => $value, $small => '"old"']);
        $this->actingAs($user)->patchJson('/api/v1/account/ui-preferences', ['entries' => [
            ['key' => $small, 'value' => 'new'],
            ['key' => 'sorting:stock:sort', 'value' => 'name_asc'],
        ]])->assertUnprocessable();
        $this->assertSame('"old"', Redis::hget($key, $small));
        $this->assertSame(2, Redis::hlen($key));
        $this->patchJson('/api/v1/account/ui-preferences', ['entries' => [
            ['key' => $large, 'value' => 'small'],
            ['key' => $small, 'value' => 'new'],
        ]])->assertNoContent();
        $this->getJson('/api/v1/account/ui-preferences')->assertOk()->assertJsonPath('data.'.$small, 'new');
    }
}
