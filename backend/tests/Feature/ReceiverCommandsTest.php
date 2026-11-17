<?php

namespace Tests\Feature;

use App\Services\MqttConfiguration;
use App\Services\MqttSubscriber;
use App\Services\RemoteEventReceiver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReceiverCommandsTest extends TestCase
{
    public static function invalidEventOptions(): array
    {
        return [['unknown', '10'], ['ha', '0'], ['hb', '56'], ['ha', '1.5']];
    }

    #[DataProvider('invalidEventOptions')]
    public function test_invalid_event_sessions_cannot_start_a_receiver(string $provider, string $seconds): void
    {
        $this->mock(RemoteEventReceiver::class)->shouldNotReceive('receive');
        Http::preventStrayRequests();
        $this->artisan('measurements:events', ['provider' => $provider, '--seconds' => $seconds])->assertExitCode(2);
        Http::assertNothingSent();
    }

    public static function validEventOptions(): array
    {
        return [['ha', 1], ['hb', 55]];
    }

    #[DataProvider('validEventOptions')]
    public function test_event_sessions_accept_both_providers_at_the_duration_boundaries(string $provider, int $seconds): void
    {
        $this->mock(RemoteEventReceiver::class)->shouldReceive('receive')->once()->with($provider, $seconds);
        $this->artisan('measurements:events', ['provider' => $provider, '--seconds' => $seconds])->assertSuccessful();
    }

    public function test_disabled_mqtt_and_held_receiver_lock_never_start_a_second_subscription(): void
    {
        $configuration = $this->mock(MqttConfiguration::class);
        $configuration->shouldReceive('ready')->once()->andReturnFalse();
        $this->mock(MqttSubscriber::class)->shouldNotReceive('receive');
        $this->artisan('measurements:mqtt', ['--seconds' => 1])->assertSuccessful();
        $configuration->shouldReceive('ready')->once()->andReturnTrue();
        $lock = Cache::lock('measurements:mqtt', 60);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('measurements:mqtt', ['--seconds' => 1])->assertSuccessful();
        } finally {
            $lock->release();
        }
    }

    public function test_mqtt_connection_failures_release_the_lock_without_logging_provider_secrets(): void
    {
        $this->mock(MqttConfiguration::class)->shouldReceive('ready')->once()->andReturnTrue();
        $this->mock(MqttSubscriber::class)->shouldReceive('receive')->once()->with(1)->andReturnUsing(function (): never {
            usleep(1_100_000);
            throw new RuntimeException('Fictional private provider credential');
        });
        Log::spy();
        $this->artisan('measurements:mqtt', ['--seconds' => 1])->assertSuccessful();
        Log::shouldHaveReceived('warning')->once()->with('mqtt_receiver.connection_failed');
        $lock = Cache::lock('measurements:mqtt', 60);
        $this->assertTrue($lock->get());
        $lock->release();
    }
}
