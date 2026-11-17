<?php

namespace App\Jobs;

use App\Services\EmailChange;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendEmailChange implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(private string $uuid, private string $email, private string $token, private string $locale) {}

    public function handle(EmailChange $changes): void
    {
        $changes->deliver($this->uuid, $this->email, $this->token, $this->locale);
    }
}
