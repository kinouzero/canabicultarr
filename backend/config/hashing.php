<?php

return [
    'driver' => 'argon2id',
    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => 1,
        'time' => (int) env('ARGON_TIME', 4),
        // Accept existing bcrypt hashes and upgrade them on a successful login.
        'verify' => false,
    ],
    'rehash_on_login' => true,
];
