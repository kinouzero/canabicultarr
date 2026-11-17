<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApplicationInfoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['app.version' => '0.1.0']);
    }

    public function test_only_active_authenticated_users_can_check_releases(): void
    {
        $this->getJson('/api/v1/application-info')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_active' => false]))->getJson('/api/v1/application-info')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_public_releases_are_compared_cached_and_linked_to_the_fixed_repository(): void
    {
        Http::fake(['api.github.com/*' => Http::response([
            $this->release('v0.1.1'), $this->release('v0.2.0-rc.1'),
            $this->release('v9.0.0', true), $this->release('https://untrusted.example/'),
        ])]);
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/application-info')->assertOk()->assertJsonPath('data.version', '0.1.0')
            ->assertJsonPath('data.update.status', 'available')->assertJsonPath('data.update.latestVersion', '0.2.0-rc.1')
            ->assertJsonPath('data.update.prerelease', true)
            ->assertJsonPath('data.update.releaseUrl', 'https://github.com/kinouzero/canabicultarr/releases/tag/v0.2.0-rc.1');
        $this->getJson('/api/v1/application-info')->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/repos/kinouzero/canabicultarr/releases?per_page=100' && ! $request->hasHeader('Authorization'));
        $this->travel(11)->hours();
        $this->getJson('/api/v1/application-info')->assertOk();
        Http::assertSentCount(1);
        $this->travel(2)->hours();
        $this->getJson('/api/v1/application-info')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_same_version_and_older_prereleases_do_not_offer_an_update(): void
    {
        Http::fake(['api.github.com/*' => Http::response([$this->release('v0.1.0-beta.2'), $this->release('v0.1.0')])]);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/application-info')->assertOk()->assertJsonPath('data.update.status', 'current');
    }

    public function test_no_release_is_distinct_from_an_unavailable_check(): void
    {
        $this->actingAs(User::factory()->create());
        $cases = [[200, [], 'noRelease'], [404, [], 'inaccessible'], [429, [], 'limited'], [503, [], 'unavailable'], [200, ['message' => 'invalid'], 'unavailable']];
        $sequence = Http::sequence();
        foreach ($cases as [$code, $body]) {
            $sequence->push($body, $code);
        }
        Http::fake(['api.github.com/*' => $sequence]);
        foreach ($cases as [$code, $body, $status]) {
            Cache::flush();
            $this->getJson('/api/v1/application-info')->assertOk()->assertJsonPath('data.update.status', $status)->assertJsonPath('data.update.latestVersion', null);
        }
    }

    public function test_network_failure_does_not_claim_the_app_is_current(): void
    {
        Http::fake(['api.github.com/*' => Http::failedConnection()]);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/application-info')->assertOk()->assertJsonPath('data.update.status', 'unavailable');
    }

    private function release(string $tag, bool $draft = false): array
    {
        return ['tag_name' => $tag, 'draft' => $draft, 'prerelease' => str_contains($tag, '-'), 'published_at' => '2026-11-16T12:00:00Z', 'html_url' => 'https://untrusted.example/'];
    }
}
