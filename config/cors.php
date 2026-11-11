<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Which browser origins may call the API. On Kubernetes HAProxy answered the
    | preflight; on Cloud Run nothing sits in front of the container, so the
    | framework's HandleCors middleware does it with these values.
    |
    | CORS_ALLOWED_ORIGINS is a comma-separated list of origins; unset, only the
    | SPA (FRONTEND_URL) is allowed. The API authenticates with Bearer tokens,
    | not cookies, so credentials stay disabled.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    'supports_credentials' => false,

];
