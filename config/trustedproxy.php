<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Proxies whose X-Forwarded-* headers the application honours, as a
    | comma-separated list of IPs or CIDRs ("*" trusts every hop, which lets a
    | client forge its IP). In production: the cluster pod range (HAProxy), the
    | Google Front End ranges that reach the backends and the Gateway address
    | that GCLB appends to X-Forwarded-For. Unset means no proxy is trusted.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
