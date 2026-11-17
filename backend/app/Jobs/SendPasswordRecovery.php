<?php

namespace App\Jobs;

use App\Services\PasswordRecovery;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPasswordRecovery implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(private string $email, private string $locale, private ?int $version, private int $requestedAt) {}

    public function handle(PasswordRecovery $recovery): void
    {
        $recovery->deliver($this->email, $this->locale, $this->version, $this->requestedAt);
    }
}
