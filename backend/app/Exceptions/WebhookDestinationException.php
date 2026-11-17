<?php

namespace App\Exceptions;

use RuntimeException;

class WebhookDestinationException extends RuntimeException
{
    public function __construct(public readonly bool $retryable = false)
    {
        parent::__construct('Webhook destination unavailable or disallowed.');
    }
}
