<?php

namespace Tests\Feature;

use App\Exceptions\AuthFlowException;
use App\Mail\AccountActivationMail;
use App\Mail\EmailChangeMail;
use App\Mail\PasswordRecoveryMail;
use App\Models\ConnectionSetting;
use App\Models\User;
use App\Services\AccountActivation;
use App\Services\AccountProfile;
use App\Services\EmailChange;
use App\Services\PasswordRecovery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\Fixtures\QueuedCacheProbe;
use Tests\TestCase;

class RedisQueueTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('REDIS_TESTS') !== '1') {
            $this->markTestSkipped('Requires the isolated Redis test container.');
        }
        config(['queue.default' => 'redis', 'cache.default' => 'redis', 'database.redis.cache.database' => 14]);
        Cache::flush();
        Queue::connection()->clear('account-activation');
        Queue::connection()->clear('password-recovery');
        Queue::connection()->clear('queue-probe');
        Mail::fake();
        config(['app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test']);
        foreach (['MAIL_MAILER', 'MAIL_HOST', 'MAIL_FROM_ADDRESS'] as $field) {
            config(['connection_settings.mail.'.$field.'.locked' => false]);
        }
        ConnectionSetting::findOrFail('mail')->update(['values' => ['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_FROM_ADDRESS' => 'accounts@example.test']]);
    }

    public function test_cache_locks_and_released_jobs_survive_worker_restarts(): void
    {
        Cache::put('probe', 'retained', 60);
        $first = Cache::lock('exclusive', 30);
        $second = Cache::lock('exclusive', 30);
        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
        $first->release();
        $this->assertTrue($second->get());
        $second->release();
        Queue::connection()->push(new QueuedCacheProbe('retried'), '', 'queue-probe');
        (new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue=queue-probe', '--once', '--tries=3'], base_path(), ['QUEUE_CONNECTION' => 'redis', 'CACHE_STORE' => 'redis', 'REDIS_DB' => '15', 'REDIS_CACHE_DB' => '14']))->mustRun();
        $this->assertNull(Cache::get('retried'));
        $this->assertSame(1, Queue::connection()->size('queue-probe'));
        (new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue=queue-probe', '--once', '--tries=3'], base_path(), ['QUEUE_CONNECTION' => 'redis', 'CACHE_STORE' => 'redis', 'REDIS_DB' => '15', 'REDIS_CACHE_DB' => '14']))->mustRun();
        $this->assertSame(2, (int) Cache::get('retried'));
        $this->assertSame('retained', Cache::get('probe'));
        $this->assertSame(0, Queue::connection()->size('queue-probe'));
    }

    public function test_activation_dispatches_only_after_commit_and_rollbacks_enqueue_nothing(): void
    {
        $user = User::factory()->create(['email_verified_at' => null, 'email_activation_required' => true]);
        DB::beginTransaction();
        app(AccountActivation::class)->begin($user, 'fr');
        $this->assertSame(0, Queue::connection()->size('account-activation'));
        DB::rollBack();
        $this->assertDatabaseCount('account_activation_tokens', 0);
        $this->assertSame(0, Queue::connection()->size('account-activation'));
        DB::transaction(function () use ($user) {
            app(AccountActivation::class)->begin($user, 'fr');
            $this->assertSame(0, Queue::connection()->size('account-activation'));
        });
        $this->assertSame(1, Queue::connection()->size('account-activation'));
        $job = Queue::connection()->pop('account-activation');
        $job->fire();
        $job->delete();
        Mail::assertSent(AccountActivationMail::class);
        $this->assertSame(0, Queue::connection()->size('account-activation'));
    }

    public function test_workers_reload_saved_connection_settings_before_processing_a_job(): void
    {
        $row = ConnectionSetting::findOrFail('mail');
        $row->update(['values' => [...$row->values, 'MAIL_HOST' => 'updated.example.test']]);
        Queue::connection()->push(new QueuedCacheProbe('fresh-settings'), '', 'queue-probe');
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'queue-probe', '--once' => true]);
        $this->assertSame('updated.example.test', config('mail.mailers.smtp.host'));
    }

    public function test_recovery_uses_the_configured_encrypted_redis_queue(): void
    {
        $user = User::factory()->create();
        config(['password_recovery.enabled' => true, 'password_recovery.url' => 'https://culture.example.test']);
        app(PasswordRecovery::class)->request($user->email, 'en');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(1, Queue::connection()->size('password-recovery'));
        $payload = Redis::connection()->lindex('queues:password-recovery', 0);
        $this->assertStringNotContainsString($user->email, $payload);
        $job = Queue::connection()->pop('password-recovery');
        $job->fire();
        $job->delete();
        Mail::assertSent(PasswordRecoveryMail::class);
    }

    public function test_post_commit_transport_failure_is_sanitized_and_resend_recovers(): void
    {
        $user = User::factory()->create(['email_verified_at' => null, 'email_activation_required' => true]);
        $manager = Queue::getFacadeRoot();
        Queue::shouldReceive('connection')->withNoArgs()->andThrow(new \RuntimeException('Private Redis credentials'));
        try {
            DB::transaction(fn () => app(AccountActivation::class)->begin($user, 'fr'));
            $this->fail('Dispatch should reject the unavailable queue.');
        } catch (AuthFlowException $error) {
            $this->assertSame('accountActivation.unavailable', $error->getMessage());
        }
        $this->assertTrue($user->fresh()->email_activation_required);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('account_activation_tokens', 1);
        Queue::swap($manager);
        app(AccountActivation::class)->resend($user->email, 'fr');
        $this->assertSame(1, Queue::connection()->size('account-activation'));
        $job = Queue::connection()->pop('account-activation');
        $job->fire();
        $job->delete();
        Mail::assertSent(AccountActivationMail::class);
    }

    public function test_old_database_email_jobs_can_be_drained_without_moving_or_losing_them(): void
    {
        $user = User::factory()->create(['email_verified_at' => null, 'email_activation_required' => true]);
        config(['queue.default' => 'database']);
        DB::transaction(fn () => app(AccountActivation::class)->begin($user, 'en'));
        $this->assertDatabaseCount('jobs', 1);
        config(['queue.default' => 'redis']);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'account-activation,password-recovery', '--stop-when-empty' => true, '--tries' => 1]);
        Mail::assertSent(AccountActivationMail::class);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, Queue::connection()->size('account-activation'));
    }

    public function test_email_change_dispatches_after_commit_and_recovers_from_queue_failure(): void
    {
        $user = User::factory()->create();
        DB::beginTransaction();
        app(EmailChange::class)->request($user, $user, 'replacement@example.test', 'fr');
        $this->assertSame(0, Queue::connection()->size('account-activation'));
        DB::rollBack();
        $this->assertDatabaseCount('pending_email_changes', 0);
        RateLimiter::clear('email-change:'.$user->uuid);
        $manager = Queue::getFacadeRoot();
        Queue::shouldReceive('connection')->withNoArgs()->andThrow(new \RuntimeException('Private queue credentials'));
        try {
            app(AccountProfile::class)->update($user, ['name' => $user->name, 'email' => 'replacement@example.test']);
            $this->fail('Dispatch should reject the unavailable queue.');
        } catch (AuthFlowException $error) {
            $this->assertSame('emailChange.unavailable', $error->getMessage());
        }
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame(0, $user->fresh()->session_version);
        $this->assertDatabaseHas('pending_email_changes', ['user_id' => $user->id, 'queued_at' => null]);
        Queue::swap($manager);
        RateLimiter::clear('email-change:'.$user->uuid);
        app(AccountProfile::class)->update($user, ['name' => $user->name, 'email' => 'replacement@example.test']);
        $this->assertSame(1, Queue::connection()->size('account-activation'));
        $this->assertNotNull(DB::table('pending_email_changes')->value('queued_at'));
        $job = Queue::connection()->pop('account-activation');
        $job->fire();
        $job->delete();
        Mail::assertSent(EmailChangeMail::class);
        $this->assertSame(0, Queue::connection()->size('account-activation'));
    }
}
