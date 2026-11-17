<?php

namespace Tests\Feature;

use App\Mail\MonitoringAlertMail;
use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\MeasurementType;
use App\Models\NotificationDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\AssetAdministration;
use App\Services\MonitoringAlerts;
use App\Services\NotificationAdministration;
use App\Services\NotificationConfiguration;
use App\Services\NotificationDispatcher;
use App\Services\NotificationOutbox;
use App\Services\NotificationResult;
use App\Services\SensorAdministration;
use App\Services\WebhookDnsResolver;
use App\Services\WebPushDelivery;
use Database\Seeders\AccessProfileSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->mock(WebhookDnsResolver::class)->shouldReceive('addresses')->with('notify.example.test')->andReturn(['93.184.216.34']);
        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo(now()->startOfSecond());
        $keys = VAPID::createVapidKeys();
        config(['mail.default' => 'smtp', 'mail.from.address' => 'sender@example.test', 'mail.mailers.smtp.host' => 'mail.example.test',
            'alert_notifications.push.public_key' => $keys['publicKey'], 'alert_notifications.push.private_key' => $keys['privateKey'], 'alert_notifications.push.subject' => 'mailto:admin@example.test',
            'alert_notifications.webhook.url' => 'https://notify.example.test/hook', 'alert_notifications.webhook.token' => 'fictional-secret-token', 'alert_notifications.webhook.format' => 'generic']);
    }

    private function admin(): User
    {
        return User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function settings(User $admin, array $extra = []): array
    {
        return app(NotificationAdministration::class)->saveSettings($admin, [...app(NotificationConfiguration::class)->settings(), ...$extra]);
    }

    private function preferences(User $user, array $extra = []): array
    {
        return app(NotificationAdministration::class)->savePreferences($user, [...app(NotificationAdministration::class)->preferences($user), ...$extra]);
    }

    private function rule(User $admin): AlertRule
    {
        $device = app(AssetAdministration::class)->save($admin, ['name' => 'Fictional Notification Device']);
        $type = MeasurementType::create(['name' => 'Fictional Type', 'code' => 'fictional_notify_type', 'unit' => 'test']);
        $sensor = app(SensorAdministration::class)->save($admin, ['name' => 'Fictional Notification Probe', 'device_id' => $device->id, 'measurement_type_id' => $type->id, 'channel_key' => 'probe']);

        return AlertRule::create(['name' => 'Fictional Rule', 'sensor_id' => $sensor->id, 'kind' => 'missing', 'severity' => 'warning', 'delay_seconds' => 120, 'activated_at' => now()->subHour()]);
    }

    private function opened(User $admin): AlertEpisode
    {
        $rule = $this->rule($admin);
        app(AlertEvaluator::class)->evaluate($rule);

        return AlertEpisode::where('alert_rule_id', $rule->id)->sole();
    }

    private function webhook(): array
    {
        $admin = $this->admin();
        $this->settings($admin, ['webhook_enabled' => true]);
        $episode = $this->opened($admin);

        return [$admin, $episode, NotificationDelivery::sole()];
    }

    private function subscriptionInput(string $suffix = 'fixture'): array
    {
        $keys = VAPID::createVapidKeys();

        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix, 'keys' => ['p256dh' => $keys['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')]];
    }

    public function test_guests_are_denied_and_all_channels_default_disabled(): void
    {
        foreach (['settings', 'preferences', 'subscriptions', 'deliveries'] as $path) {
            $this->getJson('/api/v1/notifications/'.$path)->assertUnauthorized();
        }
        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/v1/notifications/settings')->assertOk()->assertJsonPath('data.canManage', false)->assertJsonPath('data.settings.email_enabled', false)->assertJsonPath('data.settings.push_enabled', false)->assertJsonPath('data.settings.webhook_enabled', false);
        foreach (['fictional-secret-token', 'notify.example.test', config('alert_notifications.push.private_key'), $user->email] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/api/v1/notifications/preferences')->assertOk()->assertJsonPath('data.email_enabled', false)->assertJsonPath('data.push_enabled', false);
        $this->assertDatabaseCount('notification_settings', 0);
        $this->assertDatabaseCount('notification_preferences', 0);
    }

    public function test_members_cannot_change_global_settings_and_revoked_sessions_fail(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/v1/notifications/settings', NotificationConfiguration::DEFAULTS)->assertForbidden();
        $user->forceFill(['session_version' => 1])->save();
        $this->actingAs($user)->withSession(['auth_version' => 0])->getJson('/api/v1/notifications/settings')->assertUnauthorized();
    }

    public function test_admin_settings_use_versions_and_reject_unconfigured_or_log_mailers(): void
    {
        $admin = $this->admin();
        config(['mail.default' => 'log']);
        $this->actingAs($admin)->patchJson('/api/v1/notifications/settings', [...NotificationConfiguration::DEFAULTS, 'email_enabled' => true])->assertUnprocessable()->assertJsonPath('error.code', 'notifications.errors.notConfigured');
        $this->settings($admin, ['webhook_enabled' => true]);
        $this->patchJson('/api/v1/notifications/settings', NotificationConfiguration::DEFAULTS)->assertConflict();
        $this->getJson('/api/v1/notifications/settings')->assertJsonPath('data.settings.version', 1)->assertJsonPath('data.settings.webhook_enabled', true);
    }

    public function test_opt_in_is_required_and_enabling_does_not_backfill_old_episodes(): void
    {
        $admin = $this->admin();
        $episode = $this->opened($admin);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->settings($admin, ['email_enabled' => true]);
        app(AlertEvaluator::class)->evaluate(AlertRule::find($episode->alert_rule_id));
        $this->preferences($admin, ['email_enabled' => true]);
        app(AlertEvaluator::class)->evaluate(AlertRule::find($episode->alert_rule_id));
        $this->assertDatabaseCount('notification_deliveries', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_outbox_is_transactional_and_duplicate_events_are_deduplicated(): void
    {
        [$admin, $episode] = $this->webhook();
        app(NotificationOutbox::class)->enqueue($episode, 'opened');
        $this->assertDatabaseCount('notification_deliveries', 1);
        $before = NotificationDelivery::count();
        DB::beginTransaction();
        $other = $episode->replicate(['id']);
        $other->resolved_at = now();
        $other->save();
        app(NotificationOutbox::class)->enqueue($other, 'opened');
        DB::rollBack();
        $this->assertSame($before, NotificationDelivery::count());
        Http::assertNothingSent();
    }

    public function test_severity_filter_excludes_lower_severity_and_disabled_recovery(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['webhook_enabled' => true, 'minimum_severity' => 'critical']);
        $this->opened($admin);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_email_is_only_sent_to_the_opted_in_active_account_with_safe_plain_content(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->settings($admin, ['email_enabled' => true]);
        $this->preferences($member, ['email_enabled' => true, 'locale' => 'fr']);
        $this->opened($admin);
        $delivery = NotificationDelivery::sole();
        $this->assertSame($member->id, $delivery->user_id);
        $this->assertStringNotContainsString('Fictional Rule', $delivery->getRawOriginal('payload'));
        $this->assertSame('sent', app(NotificationDispatcher::class)->dispatch($delivery->id));
        Mail::assertSent(MonitoringAlertMail::class, fn ($mail) => $mail->hasTo($member->email) && $mail->locale === 'fr' && str_contains($mail->title, 'ouverte') && str_contains($mail->render(), 'Fictional Rule'));
        $this->assertSame('skipped', app(NotificationDispatcher::class)->dispatch($delivery->id));
        Mail::assertSentCount(1);
    }

    public function test_generic_webhook_has_stable_event_id_bearer_and_idempotency_without_readings(): void
    {
        [$admin, $episode, $delivery] = $this->webhook();
        Http::fake(['notify.example.test/*' => Http::response([], 202)]);
        $this->assertSame('sent', app(NotificationDispatcher::class)->dispatch($delivery->id));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer fictional-secret-token') && $r->hasHeader('Idempotency-Key', $delivery->id) && $r['event_id'] === $delivery->id && $r['event'] === 'alert.opened' && $r['schema_version'] === 1 && ! isset($r['alert']['value']));
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public static function webhookFormats(): array
    {
        return [['ntfy'], ['apprise']];
    }

    #[DataProvider('webhookFormats')]
    public function test_ntfy_and_apprise_profiles_use_their_expected_payloads(string $format): void
    {
        config(['alert_notifications.webhook.format' => $format, 'alert_notifications.webhook.topic' => 'fixture-topic', 'alert_notifications.webhook.tag' => 'ops']);
        [$admin, $episode, $delivery] = $this->webhook();
        Http::fake(['notify.example.test/*' => Http::response([], 200)]);
        $this->assertSame('sent', app(NotificationDispatcher::class)->dispatch($delivery->id));
        Http::assertSent(fn ($r) => $format === 'ntfy' ? ($r['topic'] === 'fixture-topic' && isset($r['message'])) : ($r['format'] === 'text' && $r['tag'] === 'ops' && isset($r['body'])));
    }

    public function test_transient_failures_back_off_and_stop_after_five_attempts(): void
    {
        [$admin, $episode, $delivery] = $this->webhook();
        Http::fake(['notify.example.test/*' => Http::response([], 503)]);
        for ($i = 1; $i <= 5; $i++) {
            $result = app(NotificationDispatcher::class)->dispatch($delivery->id);
            $this->assertSame($i < 5 ? 'pending' : 'failed', $result);
            $delivery->refresh();
            $this->assertSame($i, $delivery->attempts);
            if ($i < 5) {
                $this->assertSame('skipped', app(NotificationDispatcher::class)->dispatch($delivery->id));
                $this->travelTo($delivery->next_attempt_at);
            }
        }
        Http::assertSentCount(5);
    }

    public function test_redirect_and_access_denied_are_not_retried_or_followed(): void
    {
        [$admin, $episode, $delivery] = $this->webhook();
        Http::fake(['notify.example.test/*' => Http::response('', 302, ['Location' => 'https://other.example.test/']), '*' => Http::response([], 200)]);
        $this->assertSame('failed', app(NotificationDispatcher::class)->dispatch($delivery->id));
        Http::assertSentCount(1);
    }

    public function test_destination_rejection_is_terminal_and_visible_without_network_request(): void
    {
        [$admin, $episode, $delivery] = $this->webhook();
        $this->mock(WebhookDnsResolver::class)->shouldReceive('addresses')->with('notify.example.test')->once()->andReturn(['169.254.169.254']);
        Http::fake();
        $this->assertSame('failed', app(NotificationDispatcher::class)->dispatch($delivery->id));
        $this->assertSame('notifications.errors.webhookBlocked', $delivery->fresh()->error_code);
        $this->assertSame('skipped', app(NotificationDispatcher::class)->dispatch($delivery->id));
        Http::assertNothingSent();
    }

    public function test_changed_target_cancels_without_sending(): void
    {
        [$admin, $episode, $delivery] = $this->webhook();
        config(['alert_notifications.webhook.url' => 'https://changed.example.test/hook']);
        $this->assertSame('skipped', app(NotificationDispatcher::class)->dispatch($delivery->id));
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame(0, $delivery->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_personal_opt_out_cancels_queued_email(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['email_enabled' => true]);
        $this->preferences($admin, ['email_enabled' => true]);
        $this->opened($admin);
        $delivery = NotificationDelivery::sole();
        $this->preferences($admin, ['email_enabled' => false]);
        app(NotificationDispatcher::class)->dispatch($delivery->id);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_opening_is_delivered_before_natural_recovery(): void
    {
        [$admin, $episode, $opening] = $this->webhook();
        DB::transaction(fn () => app(MonitoringAlerts::class)->resolve(AlertRule::find($episode->alert_rule_id), 'recovered'));
        $recovery = NotificationDelivery::where('event', 'recovered')->sole();
        Http::fake(['notify.example.test/*' => Http::response([], 200)]);
        $this->assertSame('skipped', app(NotificationDispatcher::class)->dispatch($recovery->id));
        $this->assertSame(0, $recovery->fresh()->attempts);
        $this->assertSame('sent', app(NotificationDispatcher::class)->dispatch($opening->id));
        $this->travelTo($recovery->fresh()->next_attempt_at);
        $this->assertSame('sent', app(NotificationDispatcher::class)->dispatch($recovery->id));
        Http::assertSentCount(2);
    }

    public function test_push_subscriptions_are_owned_encrypted_idempotent_and_hide_capability_urls(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['push_enabled' => true]);
        $input = $this->subscriptionInput();
        $reply = $this->actingAs($admin)->postJson('/api/v1/notifications/subscriptions', $input)->assertCreated();
        $id = $reply->json('data.id');
        $row = PushSubscription::find($id);
        $this->assertStringNotContainsString('fcm.googleapis.com', $row->getRawOriginal('endpoint'));
        $this->postJson('/api/v1/notifications/subscriptions', $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('push_subscriptions', 1);
        $listing = $this->getJson('/api/v1/notifications/subscriptions')->assertOk();
        $this->assertStringNotContainsString($input['endpoint'], $listing->getContent());
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/v1/notifications/subscriptions', $input)->assertConflict();
        $this->deleteJson('/api/v1/notifications/subscriptions/'.$id)->assertNotFound();
    }

    public function test_push_endpoint_ssrf_and_malformed_key_material_are_rejected(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['push_enabled' => true]);
        $input = $this->subscriptionInput();
        foreach (['http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://fcm.googleapis.com.evil.test/x', 'https://user@fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x', 'https://localhost/x'] as $endpoint) {
            $this->actingAs($admin)->postJson('/api/v1/notifications/subscriptions', [...$input, 'endpoint' => $endpoint])->assertUnprocessable();
        }
        $this->postJson('/api/v1/notifications/subscriptions', [...$input, 'keys' => ['p256dh' => 'bad', 'auth' => 'bad']])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_push_delivery_is_generic_and_expired_subscriptions_are_removed(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['push_enabled' => true]);
        app(NotificationAdministration::class)->subscribe($admin, $this->subscriptionInput());
        $this->opened($admin);
        $delivery = NotificationDelivery::sole();
        $this->mock(WebPushDelivery::class)->shouldReceive('send')->once()->andReturn(new NotificationResult(false, expired: true));
        $this->assertSame('failed', app(NotificationDispatcher::class)->dispatch($delivery->id));
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->assertSame('notifications.errors.subscriptionExpired', $delivery->fresh()->error_code);
    }

    public function test_delivery_history_page_sizes_are_bounded_and_keep_account_scope(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['email_enabled' => true]);
        $this->preferences($admin, ['email_enabled' => true]);
        $this->opened($admin);
        $original = NotificationDelivery::sole();
        for ($index = 1; $index < 25; $index++) {
            $delivery = $original->replicate();
            $delivery->recipient_key = 'fictional-page-'.$index;
            $delivery->save();
        }
        $other = User::factory()->create();
        $hidden = $original->replicate();
        $hidden->user_id = $other->id;
        $hidden->recipient_key = 'fictional-other-account';
        $hidden->save();

        $first = $this->actingAs($admin)->getJson('/api/v1/notifications/deliveries?per_page=10')->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 25)->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.last_page', 3);
        $second = $this->getJson('/api/v1/notifications/deliveries?per_page=10&page=2')->assertOk()->assertJsonCount(10, 'data');
        $this->assertEmpty(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        $this->getJson('/api/v1/notifications/deliveries?per_page=50')->assertOk()->assertJsonCount(25, 'data');
        foreach (['0', '101', 'invalid'] as $size) {
            $this->getJson('/api/v1/notifications/deliveries?per_page='.$size)->assertUnprocessable()->assertJsonValidationErrors('per_page');
        }
        $this->actingAs($other)->getJson('/api/v1/notifications/deliveries?per_page=10')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $hidden->id);
    }

    public function test_personal_history_cannot_expose_other_accounts_or_subscription_secrets(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->settings($admin, ['email_enabled' => true]);
        $this->preferences($admin, ['email_enabled' => true]);
        $this->opened($admin);
        $this->actingAs($member)->getJson('/api/v1/notifications/deliveries')->assertOk()->assertJsonCount(0, 'data');
        $response = $this->actingAs($admin)->getJson('/api/v1/notifications/deliveries')->assertOk()->assertJsonCount(1, 'data');
        foreach ([$admin->email, 'Fictional Rule', 'fictional-secret-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_lease_blocks_duplicate_workers_and_expired_lease_can_be_reclaimed(): void
    {
        [$admin, $episode, $delivery] = $this->webhook();
        $delivery->forceFill(['status' => 'sending', 'attempts' => 1, 'lease_token' => '00000000-0000-4000-8000-000000000001', 'lease_until' => now()->addMinute()])->save();
        Http::fake(['notify.example.test/*' => Http::response([], 200)]);
        $this->assertSame('skipped', app(NotificationDispatcher::class)->dispatch($delivery->id));
        Http::assertNothingSent();
        $this->travel(2)->minutes();
        $this->assertSame('sent', app(NotificationDispatcher::class)->dispatch($delivery->id));
        Http::assertSentCount(1);
    }

    public function test_dispatch_command_releases_its_lock_and_respects_disabled_defaults(): void
    {
        $admin = $this->admin();
        $this->opened($admin);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('notification_deliveries', 0);
        $lock = Cache::lock('alert-notifications.dispatch', 1);
        $this->assertTrue($lock->get());
        $lock->release();
        Http::assertNothingSent();
    }

    public function test_logout_removes_only_the_owned_current_browser_subscription(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['push_enabled' => true]);
        $input = $this->subscriptionInput();
        app(NotificationAdministration::class)->subscribe($admin, $input);
        app(NotificationAdministration::class)->subscribe($admin, $this->subscriptionInput('second'));
        $this->actingAs($admin)->postJson('/api/v1/auth/logout', ['push_endpoint' => $input['endpoint']])->assertNoContent();
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_real_web_push_library_encrypts_and_signs_through_a_mocked_psr_client(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['push_enabled' => true]);
        app(NotificationAdministration::class)->subscribe($admin, $this->subscriptionInput());
        $this->opened($admin);
        $delivery = NotificationDelivery::sole();
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(201)]));
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler]);
        $sender = \Mockery::mock(WebPushDelivery::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $sender->shouldReceive('client')->once()->andReturn($client);
        $this->app->instance(WebPushDelivery::class, $sender);
        $this->assertSame('sent', app(NotificationDispatcher::class)->dispatch($delivery->id));
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertStringStartsWith('vapid ', strtolower($request->getHeaderLine('Authorization')));
        $this->assertStringNotContainsString($delivery->id, (string) $request->getBody());
        $this->assertStringNotContainsString('Fictional Rule', (string) $request->getBody());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_disabling_and_reenabling_does_not_resurrect_queued_messages(): void
    {
        [$admin, $episode, $delivery] = $this->webhook();
        $this->settings($admin, ['webhook_enabled' => false]);
        $this->settings($admin, ['webhook_enabled' => true]);
        app(NotificationDispatcher::class)->dispatch($delivery->id);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_expired_pending_delivery_is_cancelled_without_network_access(): void
    {
        [$admin, $episode, $delivery] = $this->webhook();
        $this->travel(25)->hours();
        app(NotificationDispatcher::class)->dispatch($delivery->id);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame(0, $delivery->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_disabled_accounts_and_changed_session_versions_cancel_personal_deliveries(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $this->settings($admin, ['email_enabled' => true]);
        $this->preferences($admin, ['email_enabled' => true]);
        $this->preferences($other, ['email_enabled' => true]);
        $this->opened($admin);
        User::whereKey($admin->id)->update(['is_active' => false]);
        User::whereKey($other->id)->update(['session_version' => 1]);
        foreach (NotificationDelivery::all() as $delivery) {
            app(NotificationDispatcher::class)->dispatch($delivery->id);
            $this->assertSame('cancelled', $delivery->fresh()->status);
        }
        Mail::assertNothingSent();
    }

    public function test_noop_preferences_preserve_consent_version_and_pending_delivery(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['email_enabled' => true]);
        $preferences = $this->preferences($admin, ['email_enabled' => true]);
        $this->opened($admin);
        $updated = $this->preferences($admin);
        $this->assertSame($preferences['version'], $updated['version']);
        $this->assertSame('sent', app(NotificationDispatcher::class)->dispatch(NotificationDelivery::sole()->id));
        Mail::assertSentCount(1);
    }

    public function test_recovery_is_not_backfilled_when_opening_was_not_queued(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['email_enabled' => true]);
        $episode = $this->opened($admin);
        $this->preferences($admin, ['email_enabled' => true]);
        DB::transaction(fn () => app(MonitoringAlerts::class)->resolve(AlertRule::find($episode->alert_rule_id), 'recovered'));
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_configuration_closure_cancels_pending_opening_without_recovery(): void
    {
        [$admin, $episode, $delivery] = $this->webhook();
        DB::transaction(fn () => app(MonitoringAlerts::class)->resolve(AlertRule::find($episode->alert_rule_id), 'rule_disabled'));
        $this->assertDatabaseCount('notification_deliveries', 1);
        app(NotificationDispatcher::class)->dispatch($delivery->id);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_logout_revokes_this_session_even_without_a_client_endpoint(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['push_enabled' => true]);
        $this->actingAs($admin)->postJson('/api/v1/notifications/subscriptions', $this->subscriptionInput('current'))->assertCreated();
        $sessionId = app('session')->driver()->getId();
        $other = app(NotificationAdministration::class)->subscribe($admin, $this->subscriptionInput('other-session'), 'other-session');
        $this->withCredentials()->withCookie(config('session.cookie'), $sessionId)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertNotNull(PushSubscription::find($other['id']));
    }

    public function test_subscription_limit_is_enforced_for_the_current_account(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['push_enabled' => true]);
        $this->actingAs($admin);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/notifications/subscriptions', $this->subscriptionInput('device-'.$i))->assertCreated();
        }
        $this->postJson('/api/v1/notifications/subscriptions', $this->subscriptionInput('extra'))->assertUnprocessable()->assertJsonPath('error.code', 'notifications.errors.subscriptionLimit');
        $this->assertDatabaseCount('push_subscriptions', 10);
    }

    public function test_vapid_command_updates_shared_environment_through_symlink_privately(): void
    {
        $directory = sys_get_temp_dir().'/notification-env-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/.env';
        mkdir($directory.'/backend', 0700);
        $link = $directory.'/backend/.env';
        symlink('../.env', $link);
        file_put_contents($path, "OWNER_SETTING=fictional-value\nWEB_PUSH_PUBLIC_KEY=\nWEB_PUSH_PRIVATE_KEY=\n");
        $originalPath = app()->environmentPath();
        $originalFile = app()->environmentFile();
        try {
            app()->useEnvironmentPath($directory.'/backend');
            app()->loadEnvironmentFrom('.env');
            $this->artisan('notifications:generate-vapid')->assertSuccessful();
            $this->assertTrue(is_link($link));
            $this->assertSame(realpath($path), realpath($link));
            $contents = file_get_contents($path);
            $this->assertStringContainsString('OWNER_SETTING=fictional-value', $contents);
            $this->assertMatchesRegularExpression('/WEB_PUSH_PRIVATE_KEY=[A-Za-z0-9_-]{43}/', $contents);
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->artisan('notifications:generate-vapid')->assertFailed();
            $this->assertSame($contents, file_get_contents($path));
        } finally {
            app()->useEnvironmentPath($originalPath);
            app()->loadEnvironmentFrom($originalFile);
            unlink($link);
            rmdir($directory.'/backend');
            unlink($path);
            rmdir($directory);
        }
    }

    public function test_account_version_change_prevents_future_push_until_the_browser_opts_in_again(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['push_enabled' => true]);
        $input = $this->subscriptionInput();
        app(NotificationAdministration::class)->subscribe($admin, $input);
        User::whereKey($admin->id)->update(['session_version' => 1]);
        $admin->refresh();
        $episode = $this->opened($admin);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $subscriptions = app(NotificationAdministration::class)->subscriptions($admin);
        $this->assertFalse($subscriptions[0]['accountCurrent']);
        app(NotificationAdministration::class)->subscribe($admin, $input);
        $this->assertTrue(app(NotificationAdministration::class)->subscriptions($admin)[0]['accountCurrent']);
        // A fresh event can now target the renewed device; old openings are never backfilled.
        $rule = AlertRule::find($episode->alert_rule_id);
        DB::transaction(fn () => app(MonitoringAlerts::class)->resolve($rule, 'rule_changed'));
        app(AlertEvaluator::class)->evaluate($rule);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertNotSame($episode->id, NotificationDelivery::sole()->alert_episode_id);
        $this->assertSame(1, PushSubscription::sole()->user_version);
    }

    public function test_unbound_legacy_subscription_is_not_used_for_push(): void
    {
        $admin = $this->admin();
        $this->settings($admin, ['push_enabled' => true]);
        app(NotificationAdministration::class)->subscribe($admin, $this->subscriptionInput());
        PushSubscription::query()->update(['user_version' => null]);
        $this->opened($admin);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }
}
