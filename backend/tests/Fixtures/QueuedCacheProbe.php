<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class QueuedCacheProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $key) {}

    public function handle(): void
    {
        if ((int) $this->job->attempts() === 1) {
            $this->release(0);

            return;
        }
        Cache::put($this->key, (int) $this->job->attempts(), 60);
    }
}
