<?php

return [
    'provider_name' => env('OIDC_PROVIDER_NAME'),
    'provider_icon' => env('OIDC_PROVIDER_ICON'),
    'enabled' => env('OIDC_ENABLED', false),
    // Use the exact issuer value, including the trailing slash, from discovery.
    'issuer' => env('OIDC_ISSUER', ''),
    'client_id' => env('OIDC_CLIENT_ID', ''),
    'client_secret' => env('OIDC_CLIENT_SECRET', ''),
    'redirect' => env('OIDC_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8420'), '/').'/api/v1/auth/oidc/callback'),
];
