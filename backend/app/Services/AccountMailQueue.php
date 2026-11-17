<?php

namespace App\Services;

class AccountMailQueue
{
    public static function ready(): bool
    {
        $connection = config('queue.connections.'.config('queue.default'), []);

        return ($connection['driver'] ?? null) === 'redis'
            || (($connection['driver'] ?? null) === 'database'
                && in_array($connection['connection'] ?? null, [null, config('database.default')], true));
    }
}
