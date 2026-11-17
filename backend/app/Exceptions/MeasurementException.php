<?php

namespace App\Exceptions;

use RuntimeException;

class MeasurementException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 503, public readonly ?int $providerStatus = null)
    {
        parent::__construct($errorCode);
    }
}
