<?php

namespace App\Exceptions;

use RuntimeException;

class AuthFlowException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }
}
