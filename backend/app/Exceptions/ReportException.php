<?php

namespace App\Exceptions;

use RuntimeException;

class ReportException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($errorCode);
    }
}
