<?php

namespace App\Services;

class NotificationResult
{
    public function __construct(public readonly bool $success, public readonly bool $retryable = false, public readonly bool $expired = false, public readonly ?string $errorCode = null) {}
}
