<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap server
    |--------------------------------------------------------------------------
    |
    | Only used by the database seeder to register a first Nessus server.
    | Servers are normally managed in the UI, where their API keys are stored
    | encrypted in the database. Use the VMware host-only address of the VM
    | running Nessus (e.g. https://192.168.56.10:8834), never "localhost":
    | Laravel runs on the Windows host, not inside the VM.
    |
    */

    'bootstrap' => [
        'name' => env('NESSUS_NAME', 'Local Nessus'),
        'url' => env('NESSUS_URL'),
        'access_key' => env('NESSUS_ACCESS_KEY'),
        'secret_key' => env('NESSUS_SECRET_KEY'),
        'verify_ssl' => (bool) env('NESSUS_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('NESSUS_TIMEOUT', 30),

    'connect_timeout' => (int) env('NESSUS_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Allowed networks
    |--------------------------------------------------------------------------
    |
    | Comma-separated IPs / CIDR ranges a Nessus server URL may point to. The
    | application makes server-side requests to these URLs, so restricting them
    | to the lab network prevents it from being used to reach other systems.
    | Leave empty to allow any address.
    |
    */

    'allowed_networks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NESSUS_ALLOWED_NETWORKS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Result storage
    |--------------------------------------------------------------------------
    |
    | Raw Nessus exports and generated reports live on a private disk
    | (storage/app/nessus) under projects/{CODE}/... and are never public.
    |
    */

    'disk' => env('NESSUS_DISK', 'nessus'),

];
