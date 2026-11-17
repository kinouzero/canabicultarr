<?php

namespace App\Exceptions;

use RuntimeException;

class AssetException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($errorCode);
    }
}
