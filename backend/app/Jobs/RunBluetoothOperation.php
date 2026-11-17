<?php

namespace App\Jobs;

use App\Services\BluetoothOperations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunBluetoothOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 35;

    public bool $failOnTimeout = true;

    public function __construct(public string $operationId)
    {
        $this->onQueue('integrations');
    }

    public function handle(BluetoothOperations $operations): void
    {
        $operations->execute($this->operationId);
    }

    public function failed(?Throwable $error): void
    {
        app(BluetoothOperations::class)->fail($this->operationId, 'bluetooth.errors.unavailable');
    }
}
