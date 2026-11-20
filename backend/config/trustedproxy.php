<?php

return [
    'proxies' => array_values(array_filter(
        array_map(trim(...), explode(',', (string) env('APP_TRUSTED_PROXIES', ''))),
        static function (string $address): bool {
            [$ip, $prefix] = array_pad(explode('/', $address, 2), 2, null);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }

            $maxPrefix = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;

            return $prefix === null || (ctype_digit($prefix) && (int) $prefix > 0 && (int) $prefix <= $maxPrefix);
        },
    )),
];
